<?php

namespace RadarData\Testes;

use PHPUnit\Framework\TestCase;
use RadarData\Dados\SessaoVerify;

/** O valor da sessao vem do nome novo e, em api antiga, do antigo. */
class SessaoVerifyTest extends TestCase
{
    public function test_le_o_valor_pelo_nome_novo(): void
    {
        $s = SessaoVerify::de(['session_id' => 's1', 'status' => 'completed', 'valor_total_centavos' => 990, 'custo_total_centavos' => 990]);

        $this->assertSame(990, $s->valorCentavos);
        $this->assertSame(990, $s->custoCentavos);
    }

    public function test_api_antiga_so_com_o_nome_velho(): void
    {
        $s = SessaoVerify::de(['session_id' => 's1', 'status' => 'completed', 'custo_total_centavos' => 450]);

        $this->assertSame(450, $s->valorCentavos);
    }

    public function test_sem_valor_e_zero(): void
    {
        $this->assertSame(0, SessaoVerify::de(['session_id' => 's1', 'status' => 'pending'])->valorCentavos);
    }
}
