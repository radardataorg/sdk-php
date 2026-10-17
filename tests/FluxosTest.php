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
use RadarData\Erros\SemEscopo;
use RadarData\RadarData;

/**
 * Provisionamento de fluxo por codigo.
 *
 * O que vale guardar aqui: o verbo e o endereco (publicar e PUT no
 * proprio fluxo, para republicar nao criar um segundo), e o fato de o
 * preco NAO ser um campo que o integrador manda.
 */
class FluxosTest extends TestCase
{
    /** @var list<array{request: Request}> */
    private array $enviados = [];

    private function radar(array $respostas): RadarData
    {
        $this->enviados = [];
        $pilha = HandlerStack::create(new MockHandler($respostas));
        $pilha->push(Middleware::history($this->enviados));

        return new RadarData(new Configuracao(
            token: 'tok-de-provisionamento',
            http: new Client(['handler' => $pilha, 'base_uri' => 'https://api.exemplo/api/v2/', 'http_errors' => false]),
        ));
    }

    private function definicao(): array
    {
        return [
            'entrada' => ['placa' => ['tipo' => 'texto', 'obrigatorio' => true]],
            'etapas' => [['chave' => 'base', 'tipo' => 'consulta', 'servico' => 'veiculo', 'documento' => '{{entrada.placa}}']],
            'saidas' => [['chave' => 'modelo', 'de' => 'base.modelo']],
        ];
    }

    public function test_publicar_e_um_put_no_endereco_do_fluxo(): void
    {
        $this->radar([new Response(201, [], json_encode(['data' => ['slug' => 'laudo', 'preco' => 3990]]))])
            ->fluxos()->publicar('laudo', 'Laudo', 'VEICULO', $this->definicao(), ['motivo' => 'primeira versao']);

        $req = $this->enviados[0]['request'];
        $this->assertSame('PUT', $req->getMethod());
        $this->assertSame('/api/v2/analises/fluxos/laudo', $req->getUri()->getPath());

        $corpo = json_decode((string) $req->getBody(), true);
        $this->assertSame('Laudo', $corpo['nome']);
        $this->assertSame('VEICULO', $corpo['alvo']);
        $this->assertSame('primeira versao', $corpo['motivo']);
        $this->assertArrayHasKey('etapas', $corpo['definicao']);
        // O preco e calculado pelo servidor: mandar um aqui nao faria nada,
        // e o SDK nem oferece o campo.
        $this->assertArrayNotHasKey('preco', $corpo);
    }

    public function test_validar_nao_grava_e_devolve_o_preco(): void
    {
        $r = $this->radar([new Response(200, [], json_encode(['data' => [
            'valida' => true, 'preco' => 3990, 'preco_por_consulta' => ['veiculo' => 3990], 'preco_incerto' => false,
        ]]))])->fluxos()->validar($this->definicao());

        $this->assertSame('POST', $this->enviados[0]['request']->getMethod());
        $this->assertSame('/api/v2/analises/fluxos/validar', $this->enviados[0]['request']->getUri()->getPath());
        $this->assertSame(3990, $r['preco']);
    }

    public function test_token_sem_escopo_de_fluxos_vira_erro_proprio(): void
    {
        $this->expectException(SemEscopo::class);

        $this->radar([new Response(403, [], json_encode(['error' => ['code' => 'escopo_insuficiente', 'message' => 'Token sem escopo fluxos.']]))])
            ->fluxos()->publicar('laudo', 'Laudo', 'VEICULO', $this->definicao());
    }

    public function test_remover_desliga_pelo_slug(): void
    {
        $this->radar([new Response(200, [], json_encode(['data' => ['slug' => 'laudo', 'status' => 'DESATIVADO']]))])
            ->fluxos()->remover('laudo');

        $this->assertSame('DELETE', $this->enviados[0]['request']->getMethod());
        $this->assertSame('/api/v2/analises/fluxos/laudo', $this->enviados[0]['request']->getUri()->getPath());
    }
}
