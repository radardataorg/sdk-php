<?php

namespace RadarData\Recursos;

use RadarData\Transporte;

/**
 * Registro de operacao para o COAF (factoring). Escopo `analises`. Cada operacao registrada
 * e conferida, e a que precisa de olhar humano volta com `revisar`; a decisao de comunicar
 * ou arquivar e sua, com motivo.
 */
class Operacoes
{
    public function __construct(private readonly Transporte $t) {}

    /**
     * Registra. Repetir com a mesma chave devolve a mesma operacao, sem cobrar de novo.
     *
     * @param  array{cliente_documento: string, sacado_documento: string, valor: int, cliente_nome?: string, sacado_nome?: string, titulo?: string, tipo?: string, valor_liquido?: int, vencimento?: string, data_operacao?: string, meio_pagamento?: string, referencia?: string}  $operacao  valores em centavos, datas `AAAA-MM-DD`
     * @return array<string, mixed>
     */
    public function registrar(array $operacao, ?string $chaveIdempotencia = null): array
    {
        return $this->t->pedir('POST', 'operacoes', $operacao, chaveIdempotencia: $chaveIdempotencia ?: Consultas::chave());
    }

    /**
     * As operacoes da conta, da mais nova para a mais antiga. Vem o corpo paginado inteiro
     * (`data`, `current_page`, `last_page`, `total`). `revisar` traz so as que esperam decisao.
     *
     * @return array<string, mixed>
     */
    public function listar(int $pagina = 1, int $porPagina = 25, ?bool $revisar = null, ?string $de = null, ?string $ate = null): array
    {
        $query = array_filter([
            'page' => $pagina,
            'per_page' => $porPagina,
            'revisar' => $revisar === null ? null : (int) $revisar,
            'de' => $de,
            'ate' => $ate,
        ], fn ($v) => $v !== null);

        return $this->t->pedir('GET', 'operacoes', null, $query, envelope: true);
    }

    /** @return array<string, mixed> */
    public function mostrar(string $id): array
    {
        return $this->t->pedir('GET', 'operacoes/'.rawurlencode($id));
    }

    /**
     * `COMUNICAR` ao COAF ou `ARQUIVAR`, com motivo de 10 a 300 caracteres.
     *
     * @return array<string, mixed>
     */
    public function decidir(string $id, string $decisao, string $motivo, ?string $protocoloCoaf = null): array
    {
        $corpo = ['decisao' => $decisao, 'motivo' => $motivo];
        if ($protocoloCoaf !== null) {
            $corpo['protocolo_coaf'] = $protocoloCoaf;
        }

        return $this->t->pedir('POST', 'operacoes/'.rawurlencode($id).'/decidir', $corpo);
    }
}
