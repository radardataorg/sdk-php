<?php

namespace RadarData\Recursos;

use RadarData\Dados\SessaoVerify;
use RadarData\Transporte;

/**
 * Verify: verificacao de identidade. Escopo `verify`. GASTA SALDO.
 *
 * ATENCAO ao criar: esta rota NAO tem idempotencia na api, entao o SDK nao
 * repete a chamada sozinho. Se der timeout, NAO chame de novo as cegas:
 * mande um `referenceId` seu na criacao e, na duvida, procure por ele antes
 * de criar outra, senao voce paga duas sessoes.
 */
class Verify
{
    public function __construct(private readonly Transporte $t) {}

    /**
     * Cria a sessao e devolve a URL para mandar a pessoa.
     *
     * @param  string  $fluxo  uuid do fluxo ou o alias dele (ex.: "kyc-cnpj")
     * @param  array<string, mixed>  $input  o que o fluxo pede de entrada (ex.: cpf)
     * @param  array<string, mixed>  $opcoes  prefill, consent, redirect_url, webhook_url, reference_id
     */
    public function criarSessao(string $fluxo, array $input = [], array $opcoes = []): SessaoVerify
    {
        $corpo = ['flow_id' => $fluxo] + $opcoes;
        if ($input !== []) {
            $corpo['input'] = $input;
        }

        return SessaoVerify::de($this->t->pedir('POST', 'verify/sessions', $corpo));
    }

    public function sessao(string $id): SessaoVerify
    {
        return SessaoVerify::de($this->t->pedir('GET', 'verify/sessions/'.rawurlencode($id)));
    }

    public function cancelar(string $id): SessaoVerify
    {
        return SessaoVerify::de($this->t->pedir('POST', 'verify/sessions/'.rawurlencode($id).'/cancel'));
    }
}
