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
            chaveIdempotencia: $chaveIdempotencia ?: Consultas::chave(),
        );
    }

    /** @return array<string, mixed> */
    public function mostrar(string $id): array
    {
        return $this->t->pedir('GET', 'analises/'.rawurlencode($id));
    }

    /**
     * Responde a analise que parou esperando voce (`pendencia.tipo` = `manual`).
     *
     * O corpo e `acao`, e nao os campos de `pendencia.campos`: aqueles
     * descrevem o formulario que voce mostra para uma pessoa. Ate a 0.3.0
     * este metodo mandava `decisao`, e a api recusava toda chamada com 422.
     *
     * Ao aprovar, a resposta ja traz o proximo passo: quando e a prova de
     * vida, `pendencia.url` vem aqui, e nao num webhook separado.
     *
     * @param  string  $acao  `aprovar` ou `recusar`
     * @param  string|null  $motivo  obrigatorio ao recusar
     * @param  string|null  $responsavel  quem do seu time decidiu; alguns produtos exigem
     * @return array<string, mixed>
     */
    public function decidir(string $id, string $acao, ?string $motivo = null, ?string $responsavel = null): array
    {
        return $this->t->pedir('POST', 'analises/'.rawurlencode($id).'/decisao', array_filter([
            'acao' => strtolower($acao),
            'motivo' => $motivo,
            'responsavel' => $responsavel,
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
