<?php

namespace RadarData\Dados;

/**
 * Uma sessao de Verify.
 *
 * `url` so vem na CRIACAO: e o link que voce manda para a pessoa. Depois
 * disso a consulta devolve estado e resultado, e nao o link de novo.
 */
class SessaoVerify
{
    public function __construct(
        public readonly string $id,
        /** pending, in_progress, completed, expired, canceled. */
        public readonly string $status,
        public readonly ?string $url = null,
        /** APROVADO, REPROVADO, REVISAO_MANUAL; null enquanto nao concluiu. */
        public readonly ?string $resultado = null,
        public readonly ?string $expiraEm = null,
        public readonly ?string $referenceId = null,
        /** Quanto este run custou para voce, em centavos. */
        public readonly int $custoCentavos = 0,
        /** @var array<string, mixed> O corpo cru, para o que o SDK nao tipou. */
        public readonly array $cru = [],
    ) {}

    /** @param array<string, mixed> $d */
    public static function de(array $d): self
    {
        return new self(
            id: (string) ($d['session_id'] ?? ''),
            status: (string) ($d['status'] ?? ''),
            url: $d['url'] ?? null,
            resultado: $d['outcome'] ?? null,
            expiraEm: $d['expires_at'] ?? null,
            referenceId: $d['reference_id'] ?? null,
            custoCentavos: (int) ($d['custo_total_centavos'] ?? 0),
            cru: $d,
        );
    }

    public function concluida(): bool
    {
        return $this->status === 'completed';
    }

    public function aprovada(): bool
    {
        return $this->resultado === 'APROVADO';
    }
}
