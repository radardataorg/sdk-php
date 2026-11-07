<?php

namespace RadarData\Recursos;

use RadarData\Transporte;

/**
 * Monitoramento judicial: processo, novos processos por CPF, CNPJ, nome ou termo, e
 * diarios oficiais. Escopo `consultas`. GASTA SALDO: cada ciclo de 30 dias e cobrado
 * adiantado, na criacao e a cada renovacao.
 *
 * O aviso de novidade chega por webhook (`monitoramento_judicial.evento`). Cancelar vale
 * ate o fim do periodo pago, sem devolucao.
 *
 * Criar nao repete sozinho em erro: a api ja devolve o mesmo monitoramento quando o
 * pedido e repetido, e uma criacao sem confirmacao e conferida pela RadarData.
 */
class MonitoramentosJudiciais
{
    public function __construct(private readonly Transporte $t) {}

    /**
     * `$servico` e o slug (`monitoramento-documento`, `monitoramento-processo-diario`...), e
     * `$entrada` o que ele pede: `documento`, `nome`, `termo` ou `ufs`. `nome` no servico por
     * documento e um nome seu, para achar o monitoramento na lista.
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    public function criar(string $servico, array $entrada): array
    {
        return $this->t->pedir('POST', 'monitoramentos-judiciais', ['servico' => $servico] + $entrada);
    }

    /** @return array<int, array<string, mixed>> */
    public function listar(int $pagina = 1, int $porPagina = 25): array
    {
        return $this->t->pedir('GET', 'monitoramentos-judiciais', null, ['page' => $pagina, 'per_page' => $porPagina]);
    }

    /** @return array<string, mixed> */
    public function obter(string $id): array
    {
        return $this->t->pedir('GET', 'monitoramentos-judiciais/'.rawurlencode($id));
    }

    /**
     * Vale ate o fim do periodo pago, sem devolucao, e nao renova.
     *
     * @return array<string, mixed>
     */
    public function cancelar(string $id): array
    {
        return $this->t->pedir('DELETE', 'monitoramentos-judiciais/'.rawurlencode($id));
    }

    /**
     * O que o monitoramento achou. O conteudo some da RadarData em 30 dias: guarde do seu lado.
     *
     * @return array<int, array<string, mixed>>
     */
    public function eventos(string $id, int $pagina = 1, int $porPagina = 25): array
    {
        return $this->t->pedir('GET', 'monitoramentos-judiciais/'.rawurlencode($id).'/eventos', null, ['page' => $pagina, 'per_page' => $porPagina]);
    }
}
