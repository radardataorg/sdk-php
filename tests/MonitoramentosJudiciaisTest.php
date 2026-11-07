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
use RadarData\Erros\ErroRadarData;
use RadarData\RadarData;

/** Monitoramento judicial (D-0118): cobra por ciclo, entao criar nao pode repetir sozinho. */
class MonitoramentosJudiciaisTest extends TestCase
{
    /** @var list<array{request: Request}> */
    private array $enviados = [];

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

    public function test_cria_com_o_servico_no_corpo(): void
    {
        $m = $this->radar([new Response(201, [], json_encode(['data' => ['id' => 'abc']]))])
            ->monitoramentosJudiciais()->criar('monitoramento-documento', ['documento' => '12345678909', 'nome' => 'x']);

        $req = $this->enviados[0]['request'];
        $this->assertSame('abc', $m['id']);
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/api/v2/monitoramentos-judiciais', $req->getUri()->getPath());
        $this->assertSame(['servico' => 'monitoramento-documento', 'documento' => '12345678909', 'nome' => 'x'], json_decode((string) $req->getBody(), true));
    }

    public function test_criar_nao_repete_em_erro_de_servidor(): void
    {
        $radar = $this->radar([
            new Response(502, [], json_encode(['error' => ['code' => 'erro', 'message' => 'x']])),
            new Response(201, [], json_encode(['data' => ['id' => 'abc']])),
        ]);

        try {
            $radar->monitoramentosJudiciais()->criar('monitoramento-documento', ['documento' => '1']);
            $this->fail('deveria falhar');
        } catch (ErroRadarData) {
        }

        $this->assertCount(1, $this->enviados);
    }

    public function test_lista_obtem_le_eventos_e_cancela_pelo_id(): void
    {
        $radar = $this->radar(array_fill(0, 4, new Response(200, [], json_encode(['data' => []]))));
        $mon = $radar->monitoramentosJudiciais();

        $mon->listar(2, 10);
        $mon->obter('a/b');
        $mon->eventos('a/b');
        $mon->cancelar('a/b');

        $vistos = array_map(fn ($e) => $e['request']->getMethod().' '.$e['request']->getUri()->getPath().($e['request']->getUri()->getQuery() ? '?'.$e['request']->getUri()->getQuery() : ''), $this->enviados);
        $this->assertSame([
            'GET /api/v2/monitoramentos-judiciais?page=2&per_page=10',
            'GET /api/v2/monitoramentos-judiciais/a%2Fb',
            'GET /api/v2/monitoramentos-judiciais/a%2Fb/eventos?page=1&per_page=25',
            'DELETE /api/v2/monitoramentos-judiciais/a%2Fb',
        ], $vistos);
    }

    public function test_listar_e_eventos_devolvem_a_paginacao_junto(): void
    {
        $meta = ['pagina' => 1, 'paginas' => 3, 'total' => 60];
        $radar = $this->radar([
            new Response(200, [], json_encode(['data' => [['id' => 'a']], 'meta' => $meta])),
            new Response(200, [], json_encode(['data' => [], 'meta' => $meta])),
        ]);

        $this->assertSame(['data' => [['id' => 'a']], 'meta' => $meta], $radar->monitoramentosJudiciais()->listar());
        $this->assertSame($meta, $radar->monitoramentosJudiciais()->eventos('a')['meta']);
    }

    public function test_catalogo_diz_onde_se_usa_e_marca_o_monitoramento(): void
    {
        [$cpf, $mon] = $this->radar([new Response(200, [], json_encode(['data' => [
            ['slug' => 'cpf', 'preco' => 10, 'rota' => '/api/v2/cpf'],
            ['slug' => 'monitoramento-documento', 'preco' => 660, 'rota' => '/api/v2/monitoramentos-judiciais'],
        ]]))])->catalogo()->servicos();

        $this->assertSame('/api/v2/cpf', $cpf->rota);
        $this->assertFalse($cpf->monitoramento);
        $this->assertTrue($mon->monitoramento);
    }
}
