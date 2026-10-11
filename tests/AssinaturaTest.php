<?php

namespace RadarData\Testes;

use PHPUnit\Framework\TestCase;
use RadarData\Webhooks\Assinatura;

/**
 * A conferencia de assinatura e o ponto do SDK em que errar e perigoso: quem
 * aceita um webhook forjado aprova uma verificacao que nunca aconteceu.
 */
class AssinaturaTest extends TestCase
{
    private const SEGREDO = 'segredo-do-fluxo';

    private function assinar(string $corpo): string
    {
        return 'sha256='.hash_hmac('sha256', $corpo, self::SEGREDO);
    }

    public function test_aceita_a_assinatura_certa(): void
    {
        $corpo = '{"event":"verify.completed","outcome":"APROVADO"}';

        $this->assertTrue(Assinatura::confere($corpo, $this->assinar($corpo), self::SEGREDO));
    }

    public function test_recusa_corpo_alterado(): void
    {
        $corpo = '{"event":"verify.completed","outcome":"REPROVADO"}';
        $assinatura = $this->assinar($corpo);

        // Trocar REPROVADO por APROVADO e exatamente o ataque que isto barra.
        $forjado = str_replace('REPROVADO', 'APROVADO', $corpo);

        $this->assertFalse(Assinatura::confere($forjado, $assinatura, self::SEGREDO));
    }

    public function test_recusa_segredo_errado(): void
    {
        $corpo = '{"a":1}';

        $this->assertFalse(Assinatura::confere($corpo, $this->assinar($corpo), 'outro-segredo'));
    }

    public function test_recusa_assinatura_ausente_ou_vazia(): void
    {
        $this->assertFalse(Assinatura::confere('{"a":1}', null, self::SEGREDO));
        $this->assertFalse(Assinatura::confere('{"a":1}', '', self::SEGREDO));
    }

    public function test_recusa_segredo_vazio_em_vez_de_aceitar_qualquer_coisa(): void
    {
        // Config esquecida nao pode virar "passa tudo".
        $corpo = '{"a":1}';
        $this->assertFalse(Assinatura::confere($corpo, 'sha256='.hash_hmac('sha256', $corpo, ''), ''));
    }

    public function test_exigir_devolve_o_evento_decodificado(): void
    {
        $corpo = '{"event":"analise.concluida","id":"abc"}';

        $evento = Assinatura::exigir($corpo, $this->assinar($corpo), self::SEGREDO);

        $this->assertSame('analise.concluida', $evento['event']);
    }

    public function test_exigir_lanca_quando_nao_confere(): void
    {
        $this->expectException(\RuntimeException::class);
        Assinatura::exigir('{"a":1}', 'sha256=errado', self::SEGREDO);
    }

    public function test_reserializar_o_corpo_quebra_a_conferencia(): void
    {
        $corpo = '{"event":"verify.completed",  "outcome":"APROVADO"}';
        $assinatura = $this->assinar($corpo);

        // Este teste existe como documentacao executavel do erro mais comum
        // de quem integra: passar o array ja decodificado e reserializado em
        // vez do corpo cru muda espacos e ordem, e a assinatura nao bate.
        $reserializado = json_encode(json_decode($corpo, true));

        $this->assertNotSame($corpo, $reserializado);
        $this->assertFalse(Assinatura::confere($reserializado, $assinatura, self::SEGREDO));
        $this->assertTrue(Assinatura::confere($corpo, $assinatura, self::SEGREDO));
    }
}
