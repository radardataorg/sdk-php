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
use RadarData\Erros\NaoEncontrado;
use RadarData\RadarData;

/** D-0119: certificado digital por formulario e download de documento em bytes. */
class CertificadosEDocumentosTest extends TestCase
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

    public function test_cadastra_por_formulario_com_o_segundo_fator_achatado(): void
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'pfx');
        file_put_contents($arquivo, 'conteudo-pfx');

        $c = $this->radar([new Response(201, [], json_encode(['data' => ['id' => str_repeat('c', 24), 'titular' => 'FULANA']]))])
            ->certificados()->cadastrar($arquivo, 'segredo', 'Escritorio', [['tribunal' => 'TRF2', 'sistemas' => ['EPROC-TRF2', 'EPROC-SJRJ'], 'secret_2fa' => 'JBSWY3DP']]);
        unlink($arquivo);

        $req = $this->enviados[0]['request'];
        $corpo = (string) $req->getBody();
        $this->assertSame('FULANA', $c['titular']);
        $this->assertSame('/api/v2/certificados', $req->getUri()->getPath());
        $this->assertStringContainsString('name="certificado"; filename="certificado.pfx"', $corpo);
        $this->assertStringContainsString('conteudo-pfx', $corpo);
        $this->assertStringContainsString('name="autenticacoes[0][sistemas][1]"', $corpo);
        $this->assertStringContainsString('JBSWY3DP', $corpo);
    }

    public function test_cadastrar_nao_repete_em_erro_de_servidor(): void
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'pfx');
        $radar = $this->radar([
            new Response(503, [], json_encode(['error' => ['code' => 'indisponivel', 'message' => 'x']])),
            new Response(201, [], json_encode(['data' => []])),
        ]);

        try {
            $radar->certificados()->cadastrar($arquivo, 's');
            $this->fail('devia lançar');
        } catch (ErroRadarData) {
            $this->assertCount(1, $this->enviados);
        } finally {
            unlink($arquivo);
        }
    }

    public function test_lista_obtem_remove_e_configura_pelo_id(): void
    {
        $id = str_repeat('c', 24);
        $radar = $this->radar(array_fill(0, 4, new Response(200, [], json_encode(['data' => []]))));

        $radar->certificados()->listar();
        $radar->certificados()->obter($id);
        $radar->certificados()->remover($id);
        $radar->certificados()->configurarAutenticacoes($id, [['tribunal' => 'TJSP', 'sistemas' => ['ESAJ'], 'secret_2fa' => 'AAAA']]);

        $this->assertSame(
            ['GET certificados', "GET certificados/{$id}", "DELETE certificados/{$id}", "POST certificados/{$id}/autenticacoes"],
            array_map(fn ($e) => $e['request']->getMethod().' '.substr($e['request']->getUri()->getPath(), strlen('/api/v2/')), $this->enviados),
        );
    }

    public function test_documento_vem_em_bytes_com_tipo_e_nome(): void
    {
        $a = $this->radar([new Response(200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="documento-abc.pdf"'], '%PDF-1.4 x')])
            ->consultas()->baixarDocumento(str_repeat('d', 24));

        $this->assertSame('/api/v2/processos/documentos/'.str_repeat('d', 24), $this->enviados[0]['request']->getUri()->getPath());
        $this->assertSame(['conteudo' => '%PDF-1.4 x', 'tipo' => 'application/pdf', 'nome' => 'documento-abc.pdf'], $a);
    }

    public function test_pagina_do_diario_com_erro_continua_erro(): void
    {
        $radar = $this->radar([new Response(404, [], json_encode(['error' => ['code' => 'nao_encontrado', 'message' => 'Documento não encontrado.']]))]);

        $this->expectException(NaoEncontrado::class);
        $radar->monitoramentosJudiciais()->paginaDoDiario(str_repeat('p', 24));
    }
}
