<?php

namespace RadarData\Recursos;

use RadarData\Transporte;

/**
 * Motor de Analises. Escopo `analises`. GASTA SALDO.
 *
 * E ASSINCRONO: criar devolve o id e o resultado chega por webhook. Consultar
 * em laco funciona, mas o webhook e o caminho previsto; veja
 * `RadarData\Webhooks\Assinatura` para conferir que o aviso e nosso.
 */
class Analises
{
    public function __construct(private readonly Transporte $t) {}

    /** @return array<int, array<string, mixed>> Produtos liberados para esta conta. */
    public function produtos(): array
    {
        return $this->t->pedir('GET', 'analises/produtos');
    }

    /**
     * @param  array<string, mixed>  $campos  a entrada que o produto pede
     * @param  array<string, mixed>  $opcoes  finalidade, webhook_url
     * @return array<string, mixed>
     */
    public function criar(
        string $slug,
        array $campos,
        array $opcoes = [],
        ?string $chaveIdempotencia = null,
    ): array {
        return $this->t->pedir(
            'POST',
            'analises/'.rawurlencode($slug),
            ['campos' => $campos] + $opcoes,
            chaveIdempotencia: $chaveIdempotencia ?? Consultas::chave(),
        );
    }

    /** @return array<string, mixed> */
    public function mostrar(string $id): array
    {
        return $this->t->pedir('GET', 'analises/'.rawurlencode($id));
    }

    /** @return array<string, mixed> */
    public function decidir(string $id, string $decisao, ?string $motivo = null): array
    {
        return $this->t->pedir('POST', 'analises/'.rawurlencode($id).'/decisao', array_filter([
            'decisao' => $decisao,
            'motivo' => $motivo,
        ], fn ($v) => $v !== null));
    }

    /** @return array<string, mixed> */
    public function monitorar(string $slug, array $campos, array $opcoes = []): array
    {
        return $this->t->pedir('POST', 'analises/monitorar/'.rawurlencode($slug), ['campos' => $campos] + $opcoes);
    }

    /** @return array<int, array<string, mixed>> */
    public function monitoramentos(): array
    {
        return $this->t->pedir('GET', 'analises/monitoramentos');
    }

    /** @return array<string, mixed> */
    public function cancelarMonitoramento(string $id): array
    {
        return $this->t->pedir('DELETE', 'analises/monitoramentos/'.rawurlencode($id));
    }
}
