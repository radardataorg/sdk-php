<?php

namespace RadarData\Testes;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RadarData\Configuracao;
use RadarData\Erros\Conflito;
use RadarData\Erros\EntradaInvalida;
use RadarData\Erros\ErroDoServidor;
use RadarData\Erros\FalhaDeRede;
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

    public function test_resposta_perdida_e_409_vira_conflito_com_a_chave_no_erro(): void
    {
        $radar = $this->radar([
            new ConnectException('caiu', new Request('POST', 'mensagens')),
            new Response(409, [], json_encode(['error' => ['code' => 'idempotency_em_processamento', 'message' => 'x']])),
        ]);

        try {
            $radar->mensagens()->enviar('11999999999', 'oi');
            $this->fail('deveria lancar');
        } catch (Conflito $e) {
            $this->assertSame('idempotency_em_processamento', $e->codigo);
            $this->assertSame($this->enviados[0]['request']->getHeaderLine('Idempotency-Key'), $e->chaveIdempotencia);
        }
    }

    public function test_falha_de_rede_em_todas_as_tentativas_leva_a_chave(): void
    {
        $falha = fn () => new ConnectException('caiu', new Request('POST', 'mensagens'));
        $radar = $this->radar([$falha(), $falha(), $falha()]);

        try {
            $radar->mensagens()->enviar('11999999999', 'oi', 'minha');
            $this->fail('deveria lancar');
        } catch (FalhaDeRede $e) {
            $this->assertSame('minha', $e->chaveIdempotencia);
        }
    }

    public function test_chave_vazia_gera_uma(): void
    {
        $this->radar([new Response(201, [], json_encode(['data' => self::ACEITA, 'meta' => []]))])->mensagens()->enviar('11999999999', 'oi', '');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->enviados[0]['request']->getHeaderLine('Idempotency-Key'));
    }

    public function test_envio_incerto_nao_e_repetido(): void
    {
        $radar = $this->radar([
            new Response(504, [], json_encode(['error' => ['code' => 'envio_incerto', 'message' => 'x']])),
            new Response(201, [], json_encode(['data' => self::ACEITA, 'meta' => []])),
        ]);

        try {
            $radar->mensagens()->enviar('11999999999', 'oi');
            $this->fail('deveria lancar');
        } catch (ErroDoServidor $e) {
            $this->assertSame('envio_incerto', $e->codigo);
        }
        $this->assertCount(1, $this->enviados);
    }

    public function test_evento_assinado_sobre_o_corpo_exato(): void
    {
        $this->radar([new Response(202, [], json_encode(['data' => ['repetido' => false, 'analises' => []], 'avisos' => []]))])
            ->eventos()->enviar('pedido.criado', 'pedido-9', ['valor' => 100], 's3gr3do');

        $req = $this->enviados[0]['request'];
        $corpo = (string) $req->getBody();
        $this->assertSame('/api/v2/eventos', $req->getUri()->getPath());
        $this->assertSame(['evento' => 'pedido.criado', 'chave' => 'pedido-9', 'dados' => ['valor' => 100]], json_decode($corpo, true));
        $this->assertSame('sha256='.hash_hmac('sha256', $corpo, 's3gr3do'), $req->getHeaderLine('X-RadarData-Signature'));
        $this->assertSame('Bearer tok', $req->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $req->getHeaderLine('Content-Type'));
    }

    public function test_evento_sem_dados_manda_objeto_e_sem_segredo_vai_sem_assinatura(): void
    {
        $this->radar([new Response(202, [], json_encode(['data' => []]))])->eventos()->enviar('pedido.criado', 'pedido-9');

        $req = $this->enviados[0]['request'];
        $this->assertStringContainsString('"dados":{}', (string) $req->getBody());
        $this->assertFalse($req->hasHeader('X-RadarData-Signature'));
    }

    public function test_operacoes_registra_lista_mostra_e_decide(): void
    {
        $ok = fn () => new Response(200, [], json_encode(['data' => ['id' => 'o1']]));
        $radar = $this->radar([$ok(), $ok(), $ok(), $ok()]);

        $radar->operacoes()->registrar(['cliente_documento' => '11222333000181', 'sacado_documento' => '52998224725', 'valor' => 150000], 'op-1');
        $radar->operacoes()->listar(2, 25, true, '2026-11-01');
        $radar->operacoes()->mostrar('o1');
        $radar->operacoes()->decidir('o1', 'ARQUIVAR', 'Operacao conferida com o cliente.');

        $this->assertSame('op-1', $this->enviados[0]['request']->getHeaderLine('Idempotency-Key'));
        $this->assertSame('page=2&per_page=25&revisar=1&de=2026-11-01', $this->enviados[1]['request']->getUri()->getQuery());
        $this->assertSame('/api/v2/operacoes/o1', $this->enviados[2]['request']->getUri()->getPath());
        $this->assertSame(['decisao' => 'ARQUIVAR', 'motivo' => 'Operacao conferida com o cliente.'], json_decode((string) $this->enviados[3]['request']->getBody(), true));
    }
}
