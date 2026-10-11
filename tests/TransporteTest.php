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
use RadarData\Erros\EntradaInvalida;
use RadarData\Erros\ErroDoServidor;
use RadarData\Erros\ErroRadarData;
use RadarData\Erros\FalhaDeRede;
use RadarData\Erros\NaoAutenticado;
use RadarData\Erros\SaldoInsuficiente;
use RadarData\Erros\SemEscopo;
use RadarData\RadarData;

/**
 * O que este arquivo guarda nao e "o SDK chama a api", que e trivial, e sim as
 * decisoes que custam dinheiro do cliente se forem quebradas:
 *
 * - repetir SO onde ha `Idempotency-Key`, porque sem ela uma consulta volta a
 *   ser cobrada e criar sessao de Verify vira uma segunda sessao faturavel;
 * - a chave ser gerada sozinha, senao ninguem usa justamente no caso em que
 *   ela importa;
 * - cada status virar um erro proprio, para quem integra nao comparar string.
 */
class TransporteTest extends TestCase
{
    /** @var list<array{request: Request}> */
    private array $enviados = [];

    private function radar(array $respostas, int $tentativas = 3): RadarData
    {
        $this->enviados = [];
        $pilha = HandlerStack::create(new MockHandler($respostas));
        $pilha->push(Middleware::history($this->enviados));

        return new RadarData(new Configuracao(
            token: 'tok-de-teste',
            tentativas: $tentativas,
            http: new Client(['handler' => $pilha, 'base_uri' => 'https://api.exemplo/api/v2/', 'http_errors' => false]),
        ));
    }

    private function req(int $i = 0): Request
    {
        return $this->enviados[$i]['request'];
    }

    public function test_manda_o_token_e_se_identifica(): void
    {
        $this->radar([new Response(200, [], json_encode(['data' => ['saldo' => 1000, 'saldo_disponivel' => 900, 'modo_cobranca' => 'PRE']]))])
            ->catalogo()->saldo();

        $this->assertSame('Bearer tok-de-teste', $this->req()->getHeaderLine('Authorization'));
        $this->assertStringContainsString('radardata-sdk-php/', $this->req()->getHeaderLine('User-Agent'));
    }

    public function test_saldo_vem_em_centavos_inteiros(): void
    {
        $saldo = $this->radar([new Response(200, [], json_encode(['data' => ['saldo' => 123456, 'saldo_disponivel' => 100000, 'modo_cobranca' => 'PRE']]))])
            ->catalogo()->saldo();

        $this->assertSame(123456, $saldo->centavos);
        $this->assertSame(100000, $saldo->disponivelCentavos);
        $this->assertSame('1.234,56', $saldo->emReais());
    }

    public function test_consulta_manda_chave_de_idempotencia_sozinha(): void
    {
        $this->radar([new Response(200, [], json_encode(['data' => ['ok' => true]]))])
            ->consultas()->executar('cpf', '12345678909');

        // Sem a chave, repetir cobraria de novo. Exigir que o integrador a
        // passe significaria, na pratica, ninguem usar.
        $this->assertNotEmpty($this->req()->getHeaderLine('Idempotency-Key'));
    }

    public function test_consulta_repete_em_erro_de_servidor_com_a_mesma_chave(): void
    {
        $radar = $this->radar([
            new Response(500, [], json_encode(['error' => ['code' => 'erro', 'message' => 'x']])),
            new Response(200, [], json_encode(['data' => ['ok' => true]])),
        ]);

        $r = $radar->consultas()->executar('cpf', '12345678909');

        $this->assertSame(['ok' => true], $r);
        $this->assertCount(2, $this->enviados);
        // A MESMA chave nas duas: e isso que faz a api devolver a resposta de
        // antes em vez de cobrar outra vez.
        $this->assertSame(
            $this->req(0)->getHeaderLine('Idempotency-Key'),
            $this->req(1)->getHeaderLine('Idempotency-Key'),
        );
    }

    public function test_criar_sessao_de_verify_nao_repete(): void
    {
        $radar = $this->radar([
            new Response(500, [], json_encode(['error' => ['code' => 'erro', 'message' => 'x']])),
            new Response(201, [], json_encode(['session_id' => 'nao-deveria-chegar'])),
        ]);

        try {
            $radar->verify()->criarSessao('kyc', ['cpf' => '12345678909']);
            $this->fail('deveria ter lançado');
        } catch (ErroDoServidor) {
            // esperado
        }

        // UMA chamada so. Esta rota nao tem idempotencia na api, entao repetir
        // criaria uma SEGUNDA sessao faturavel.
        $this->assertCount(1, $this->enviados);
        $this->assertSame('', $this->req()->getHeaderLine('Idempotency-Key'));
    }

    public function test_cada_status_vira_um_erro_proprio(): void
    {
        $casos = [
            401 => NaoAutenticado::class,
            402 => SaldoInsuficiente::class,
            403 => SemEscopo::class,
            422 => EntradaInvalida::class,
        ];

        foreach ($casos as $status => $classe) {
            $radar = $this->radar([new Response($status, [], json_encode([
                'error' => ['code' => 'codigo_'.$status, 'message' => 'mensagem '.$status],
            ]))], tentativas: 1);

            try {
                $radar->catalogo()->saldo();
                $this->fail("status {$status} deveria ter lançado");
            } catch (ErroRadarData $e) {
                $this->assertInstanceOf($classe, $e);
                // O `code` e o que da para decidir no codigo; a mensagem muda.
                $this->assertSame('codigo_'.$status, $e->codigo);
                $this->assertSame($status, $e->status);
            }
        }
    }

    public function test_sem_resposta_e_falha_de_rede_e_nao_erro_de_servidor(): void
    {
        $radar = $this->radar([
            new ConnectException('timeout', new Request('GET', 'saldo')),
        ], tentativas: 1);

        // A diferenca importa: aqui NAO se sabe se a operacao aconteceu, e a
        // acao certa e consultar pela chave, nao repetir as cegas.
        $this->expectException(FalhaDeRede::class);
        $radar->catalogo()->saldo();
    }

    public function test_resposta_sem_envelope_data_tambem_funciona(): void
    {
        // A sessao de Verify responde no corpo raiz, o catalogo responde em
        // `data`. Quem chama nao deveria ter de saber disso.
        $sessao = $this->radar([new Response(201, [], json_encode([
            'session_id' => 'abc', 'url' => 'https://verify.exemplo/s/t', 'status' => 'pending',
            'expires_at' => '2026-10-11T12:00:00-03:00',
        ]))])->verify()->criarSessao('kyc');

        $this->assertSame('abc', $sessao->id);
        $this->assertSame('https://verify.exemplo/s/t', $sessao->url);
        $this->assertFalse($sessao->concluida());
    }
}
