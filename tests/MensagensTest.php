<?php

namespace RadarData\Testes;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RadarData\Configuracao;
use RadarData\Erros\EntradaInvalida;
use RadarData\RadarData;

/** Mensagens e resultado de consulta (D-0130): o SMS repete com a mesma chave, porque a api devolve a primeira. */
class MensagensTest extends TestCase
{
    /** @var list<array{request: Request}> */
    private array $enviados = [];

    private const ACEITA = ['id' => 'k3x9q2m7v1w8z4t6r0p5n2bc', 'canal' => 'sms', 'para' => '(11) *****-9999', 'status' => 'aceita', 'erro' => null, 'preco' => 40];

    private function radar(array $respostas): RadarData
    {
        $this->enviados = [];
        $pilha = HandlerStack::create(new MockHandler($respostas));
        $pilha->push(Middleware::history($this->enviados));

        return new RadarData(new Configuracao(
            token: 'tok',
            http: new Client(['handler' => $pilha, 'base_uri' => 'https://api.exemplo/api/v2/', 'http_errors' => false]),
        ));
    }

    public function test_envia_sms_com_a_chave_e_devolve_mensagem_e_saldo(): void
    {
        $r = $this->radar([new Response(201, [], json_encode(['data' => self::ACEITA, 'meta' => ['saldo' => 9960, 'preco' => 40, 'sandbox' => false]]))])
            ->mensagens()->enviar('+5511999999999', 'Seu codigo e 482913.', 'pedido-1-codigo');

        $req = $this->enviados[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/api/v2/mensagens', $req->getUri()->getPath());
        $this->assertSame('pedido-1-codigo', $req->getHeaderLine('Idempotency-Key'));
        $this->assertSame(['canal' => 'sms', 'para' => '+5511999999999', 'texto' => 'Seu codigo e 482913.'], json_decode((string) $req->getBody(), true));
        $this->assertSame('aceita', $r['mensagem']['status']);
        $this->assertSame(9960, $r['meta']['saldo']);
    }

    public function test_sem_chave_gera_uma_e_repete_com_a_mesma_depois_de_503(): void
    {
        $radar = $this->radar([
            new Response(503, [], json_encode(['error' => ['code' => 'fornecedor_offline', 'message' => 'fora']])),
            new Response(201, [], json_encode(['data' => self::ACEITA, 'meta' => []])),
        ]);

        $radar->mensagens()->enviar('11999999999', 'oi');

        $this->assertCount(2, $this->enviados);
        $chave = $this->enviados[0]['request']->getHeaderLine('Idempotency-Key');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $chave);
        $this->assertSame($chave, $this->enviados[1]['request']->getHeaderLine('Idempotency-Key'));
    }

    public function test_emoji_vira_entrada_invalida_com_o_codigo_da_api(): void
    {
        $radar = $this->radar([new Response(422, [], json_encode(['error' => ['code' => 'texto_invalido', 'message' => 'x']]))]);

        try {
            $radar->mensagens()->enviar('11999999999', "oi \u{1F600}");
            $this->fail('deveria lancar');
        } catch (EntradaInvalida $e) {
            $this->assertSame('texto_invalido', $e->codigo);
        }
        $this->assertCount(1, $this->enviados);
    }

    public function test_lista_paginada_e_mostra_uma(): void
    {
        $radar = $this->radar([
            new Response(200, [], json_encode(['data' => [self::ACEITA], 'meta' => ['pagina' => 2, 'paginas' => 3, 'total' => 51]])),
            new Response(200, [], json_encode(['data' => self::ACEITA + ['texto' => 'oi']])),
        ]);

        $pagina = $radar->mensagens()->listar(2, 25);
        $uma = $radar->mensagens()->obter(self::ACEITA['id']);

        $this->assertSame('page=2&per_page=25', $this->enviados[0]['request']->getUri()->getQuery());
        $this->assertSame(51, $pagina['meta']['total']);
        $this->assertSame('/api/v2/mensagens/'.self::ACEITA['id'], $this->enviados[1]['request']->getUri()->getPath());
        $this->assertSame('oi', $uma['texto']);
    }

    public function test_resultado_de_consulta_devolve_o_corpo_inteiro(): void
    {
        $r = $this->radar([new Response(200, [], json_encode(['data' => null, 'meta' => ['consulta_id' => 'abc', 'status' => 'em_andamento']]))])
            ->consultas()->resultado('abc');

        $this->assertSame('/api/v2/consultas/abc', $this->enviados[0]['request']->getUri()->getPath());
        $this->assertSame('em_andamento', $r['meta']['status']);
    }
}
