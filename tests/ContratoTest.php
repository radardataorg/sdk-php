<?php

namespace RadarData\Testes;

use PHPUnit\Framework\TestCase;
use RadarData\Configuracao;
use RadarData\Erros\EntradaInvalida;
use RadarData\Erros\NaoEncontrado;
use RadarData\RadarData;

/**
 * Contrato contra uma api de verdade (D-0130). Os outros testes usam respostas falsas, e
 * foi assim que `decidir()` mandou o campo errado sem ninguem ver. Roda so com as duas
 * variaveis, e com token de SANDBOX: nada gasta saldo nem manda mensagem.
 *
 *   RADARDATA_CONTRATO_BASE=http://127.0.0.1:8765/api/v2 RADARDATA_CONTRATO_TOKEN=... vendor/bin/phpunit tests/ContratoTest.php
 */
class ContratoTest extends TestCase
{
    private RadarData $radar;

    protected function setUp(): void
    {
        $base = getenv('RADARDATA_CONTRATO_BASE');
        $token = getenv('RADARDATA_CONTRATO_TOKEN');
        if (! $base || ! $token) {
            $this->markTestSkipped('Sem RADARDATA_CONTRATO_BASE e RADARDATA_CONTRATO_TOKEN.');
        }
        $this->radar = new RadarData(new Configuracao(token: $token, base: $base, tentativas: 1));
    }

    public function test_mensagens_envia_em_sandbox_repete_pela_chave_lista_e_mostra(): void
    {
        $chave = 'contrato-php-'.time();
        $r = $this->radar->mensagens()->enviar('+5519984250269', 'Contrato do SDK: ação', $chave);
        $this->assertSame('aceita', $r['mensagem']['status']);
        $this->assertTrue($r['meta']['sandbox']);

        $this->assertSame($r['mensagem']['id'], $this->radar->mensagens()->enviar('+5519984250269', 'Contrato do SDK: ação', $chave)['mensagem']['id']);
        $this->assertContains($r['mensagem']['id'], array_column($this->radar->mensagens()->listar(1, 5)['data'], 'id'));
        $this->assertSame('Contrato do SDK: acao', $this->radar->mensagens()->obter($r['mensagem']['id'])['texto']);
    }

    public function test_emoji_volta_texto_invalido(): void
    {
        $this->expectException(EntradaInvalida::class);
        $this->radar->mensagens()->enviar('+5519984250269', "oi \u{1F600}");
    }

    public function test_consulta_que_nao_existe_volta_nao_encontrado(): void
    {
        $this->expectException(NaoEncontrado::class);
        $this->radar->consultas()->resultado('0000000000000000');
    }

    public function test_fluxos_lista(): void
    {
        $this->assertIsArray($this->radar->fluxos()->listar());
    }
}
