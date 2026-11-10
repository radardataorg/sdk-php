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
use RadarData\Erros\MuitasTentativas;
use RadarData\RadarData;

/**
 * Documentos e decisao manual do Motor de Analises.
 *
 * O que vale guardar: a decisao vai em `acao` (ate a 0.3.0 ia em
 * `decisao` e a api recusava sempre), e o envio de documento nunca se
 * repete sozinho, porque a api nao tem idempotencia ali.
 */
class AnalisesTest extends TestCase
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
            tentativas: 3,
            http: new Client(['handler' => $pilha, 'base_uri' => 'https://api.exemplo/api/v2/', 'http_errors' => false]),
        ));
    }

    public function test_decidir_manda_acao_e_responsavel(): void
    {
        $r = $this->radar([new Response(200, [], json_encode(['data' => [
            'id' => 'a1', 'estado' => 'COLETA', 'pendencia' => ['tipo' => 'verify', 'url' => 'https://verify/s/x'],
        ]]))])->analises()->decidir('a1', 'APROVAR', 'Conferido.', 'ana@loja.com.br');

        $req = $this->enviados[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/api/v2/analises/a1/decisao', $req->getUri()->getPath());
        $this->assertSame(
            ['acao' => 'aprovar', 'motivo' => 'Conferido.', 'responsavel' => 'ana@loja.com.br'],
            json_decode((string) $req->getBody(), true),
        );
        $this->assertSame('https://verify/s/x', $r['pendencia']['url']);
    }

    public function test_decidir_sem_opcionais_nao_manda_nulo(): void
    {
        $this->radar([new Response(200, [], json_encode(['data' => ['id' => 'a1']]))])
            ->analises()->decidir('a1', 'recusar');

        $this->assertSame(['acao' => 'recusar'], json_decode((string) $this->enviados[0]['request']->getBody(), true));
    }

    public function test_enviar_documento_e_multipart_com_arquivo_e_tipo(): void
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($arquivo, "%PDF-1.4\nconteudo");

        $r = $this->radar([new Response(201, [], json_encode(['data' => [
            'id' => 'doc123', 'tipo' => 'identidade', 'tamanho' => 17, 'expira_em' => '2026-11-11T10:00:00-03:00',
        ]]))])->documentos()->enviar($arquivo, 'identidade');
        unlink($arquivo);

        $req = $this->enviados[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/api/v2/documentos', $req->getUri()->getPath());
        $this->assertStringStartsWith('multipart/form-data', $req->getHeaderLine('Content-Type'));
        $this->assertFalse($req->hasHeader('Idempotency-Key'));

        $corpo = (string) $req->getBody();
        $this->assertStringContainsString('name="arquivo"', $corpo);
        $this->assertStringContainsString('%PDF-1.4', $corpo);
        $this->assertStringContainsString('name="tipo"', $corpo);
        $this->assertStringContainsString('identidade', $corpo);
        $this->assertSame('doc123', $r['id']);
    }

    public function test_enviar_documento_aceita_recurso_aberto(): void
    {
        $recurso = fopen('php://memory', 'r+');
        fwrite($recurso, "\x89PNG\r\n\x1a\nimagem");
        rewind($recurso);

        $this->radar([new Response(201, [], json_encode(['data' => ['id' => 'doc9']]))])
            ->documentos()->enviar($recurso, 'endereco', 'comprovante.png');

        $corpo = (string) $this->enviados[0]['request']->getBody();
        $this->assertStringContainsString('filename="comprovante.png"', $corpo);
        $this->assertStringContainsString('endereco', $corpo);
    }

    public function test_enviar_documento_nao_repete_em_429(): void
    {
        $this->expectException(MuitasTentativas::class);

        try {
            $this->radar([
                new Response(429, [], json_encode(['error' => ['code' => 'muitos_documentos_pendentes', 'message' => 'Há documentos demais.']])),
                new Response(201, [], json_encode(['data' => ['id' => 'nao-deveria']])),
            ])->documentos()->enviar(fopen('php://memory', 'r'), 'identidade');
        } finally {
            $this->assertCount(1, $this->enviados);
        }
    }

    public function test_arquivo_inexistente_falha_antes_de_chamar(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->radar([])->documentos()->enviar('/nao/existe.pdf', 'identidade');
        } finally {
            $this->assertCount(0, $this->enviados);
        }
    }
}
