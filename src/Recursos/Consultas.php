<?php

namespace RadarData\Recursos;

use RadarData\Transporte;

/**
 * Consultas. Escopo `consultas`. GASTA SALDO.
 *
 * O retorno vem como array e nao como objeto tipado de proposito: cada
 * servico devolve um corpo diferente, a lista cresce, e tipar tudo aqui
 * faria o SDK desatualizar a cada servico novo sem ninguem perceber.
 */
class Consultas
{
    public function __construct(private readonly Transporte $t) {}

    /**
     * Executa uma consulta pelo slug do servico.
     *
     * A chave de idempotencia e GERADA quando voce nao passa uma, e e ela que
     * torna seguro repetir: a mesma chave devolve a mesma resposta em vez de
     * cobrar de novo. Guarde a sua se quiser poder reconsultar o resultado
     * depois de um timeout.
     *
     * @param  array<string, mixed>  $extras  campos opcionais do servico (ex.: selfie)
     * @return array<string, mixed>
     */
    public function executar(
        string $slug,
        string $documento,
        array $extras = [],
        ?string $chaveIdempotencia = null,
    ): array {
        return $this->t->pedir(
            'POST',
            $slug,
            ['documento' => $documento] + $extras,
            chaveIdempotencia: $chaveIdempotencia ?? self::chave(),
        );
    }

    /** Chave nova, no formato que a api aceita. */
    public static function chave(): string
    {
        return bin2hex(random_bytes(16));
    }
}
