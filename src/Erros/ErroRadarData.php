<?php

namespace RadarData\Erros;

/**
 * Raiz de todo erro do SDK.
 *
 * A api devolve sempre `{"error": {"code": "...", "message": "..."}}`, e o
 * `code` e o que vale para decidir no codigo: a `message` e escrita para
 * pessoa e muda. Por isso ele fica acessivel aqui, e cada subclasse existe
 * para um `catch` especifico, em vez de obrigar quem integra a comparar
 * strings.
 */
class ErroRadarData extends \RuntimeException
{
    public function __construct(
        string $mensagem,
        public readonly ?string $codigo = null,
        public readonly ?int $status = null,
        /** @var array<string, mixed> O corpo cru, para quando o SDK nao previu algo. */
        public readonly array $corpo = [],
        ?\Throwable $anterior = null,
    ) {
        parent::__construct($mensagem, $status ?? 0, $anterior);
    }
}
