<?php

namespace RadarData\Dados;

/**
 * Saldo da conta, em CENTAVOS.
 *
 * Centavo inteiro, nunca float: o sistema inteiro guarda dinheiro assim, e
 * converter para float aqui reintroduziria o erro de arredondamento que o
 * inteiro existe para evitar.
 */
class Saldo
{
    public function __construct(
        public readonly int $centavos,
        /** O que da para gastar agora: desconta o que ja esta reservado. */
        public readonly int $disponivelCentavos,
        /** PRE (saldo) ou POS (fatura). */
        public readonly string $modoCobranca,
    ) {}

    /** @param array<string, mixed> $d */
    public static function de(array $d): self
    {
        return new self(
            centavos: (int) ($d['saldo'] ?? 0),
            disponivelCentavos: (int) ($d['saldo_disponivel'] ?? 0),
            modoCobranca: (string) ($d['modo_cobranca'] ?? ''),
        );
    }

    public function emReais(): string
    {
        return number_format($this->centavos / 100, 2, ',', '.');
    }
}
