<?php

namespace RadarData\Recursos;

use RadarData\Transporte;

/**
 * Mensagens: SMS, WhatsApp, Messenger e Instagram no mesmo metodo, mudando so o `canal`.
 * Escopo `mensagens`, que NAO vem ligado por padrao no token. O SMS GASTA SALDO por
 * mensagem aceita; o que falha volta para o saldo. Messenger e Instagram nao cobram por
 * mensagem: cada pagina ou perfil conectado paga uma mensalidade.
 *
 * No SMS o texto sai no alfabeto padrao das operadoras: acento sai antes do envio, e
 * emoji recusa a mensagem (`EntradaInvalida`, codigo `texto_invalido`), sem cobrar.
 *
 * `aceita` quer dizer que saiu para a operadora, nao que chegou no aparelho. O aviso
 * chega pelos webhooks `mensagem.aceita` e `mensagem.falhou`.
 */
class Mensagens
{
    public const CANAL_SMS = 'sms';

    public const CANAL_WHATSAPP = 'whatsapp';

    public const CANAL_MESSENGER = 'messenger';

    public const CANAL_INSTAGRAM = 'instagram';

    /** Messenger e Instagram: resposta de uma pessoa da equipe, ate 7 dias depois da ultima mensagem. */
    public const ETIQUETA_ATENDIMENTO_HUMANO = 'atendimento_humano';

    public function __construct(private readonly Transporte $t) {}

    /**
     * Manda uma mensagem. No SMS e no WhatsApp, `$para` e um celular brasileiro
     * (`+5511999999999`, `11999999999` ou `(11) 99999-9999`); no SMS `$texto` tem ate 160
     * caracteres.
     *
     * A chave de idempotencia e GERADA quando voce nao passa uma, e e ela que torna seguro
     * repetir: a mesma chave devolve a primeira mensagem, sem mandar nem cobrar de novo, e
     * a de um envio que falhou devolve o mesmo erro. Para codigo de acesso, passe uma chave
     * sua ligada ao pedido (`pedido-48213-codigo`), de 1 a 80 caracteres.
     *
     * WhatsApp (`$canal = 'whatsapp'`): `$de` e o `id` do numero conectado por onde sai (sem
     * ele, o principal). Texto livre so ate 24 horas depois da ultima mensagem da pessoa, e
     * sem cobranca; fora disso, `$modelo` = `['nome' => ..., 'idioma' => 'pt_BR', 'parametros'
     * => [...]]`, aprovado na Meta, que e o que e cobrado.
     *
     * Messenger e Instagram (`$canal = 'messenger'` ou `'instagram'`): `$para` e o id da
     * pessoa, o `de` da mensagem recebida, e `$de` e a pagina ou o perfil por onde sai, o
     * `conexao` dela. Texto ate 2000 (Messenger) ou 1000 (Instagram) caracteres, so ate 24
     * horas depois da ultima mensagem da pessoa (`fora_da_janela`); com `$etiqueta =
     * 'atendimento_humano'`, ate 7 dias. Sem cobranca por mensagem; mensalidade da pagina
     * em aberto e `SaldoInsuficiente` com `mensalidade_em_aberto`.
     *
     * Erro `envio_incerto` (`ErroDoServidor`, 504): nao confirmamos se saiu, e nao foi
     * cobrado. Reenviar com chave nova pode fazer a pessoa receber duas.
     *
     * A `mensagem` traz `id`, `canal`, `para` (mascarado), `status`, `erro` e `preco`; no
     * WhatsApp, Messenger e Instagram tambem `direcao`, `conexao` (o `id` do numero, da
     * pagina ou do perfil, que e o `$de` da resposta), `entregue_em` e `lida_em`.
     *
     * @return array{mensagem: array<string, mixed>, meta: array{saldo?: int, preco?: int, sandbox?: bool}}
     */
    public function enviar(string $para, ?string $texto, ?string $chaveIdempotencia = null, string $canal = self::CANAL_SMS, ?string $de = null, ?array $modelo = null, ?string $etiqueta = null): array
    {
        $corpo = ['canal' => $canal, 'para' => $para];
        if ($modelo !== null) {
            $corpo['modelo'] = $modelo + ['idioma' => 'pt_BR', 'parametros' => []];
        } else {
            $corpo['texto'] = (string) $texto;
        }
        if ($de !== null && $de !== '') {
            $corpo['de'] = $de;
        }
        if ($etiqueta !== null && $etiqueta !== '') {
            $corpo['etiqueta'] = $etiqueta;
        }

        $r = $this->t->pedir(
            'POST',
            'mensagens',
            $corpo,
            chaveIdempotencia: $chaveIdempotencia ?: Consultas::chave(),
            envelope: true,
        );

        return ['mensagem' => $r['data'] ?? [], 'meta' => $r['meta'] ?? []];
    }

    /**
     * Os modelos de WhatsApp de um numero conectado (o principal, sem `$de`): `nome`, `idioma`,
     * `status`, `categoria`, `texto` e quantos `parametros`. Criar e aprovar e na Meta.
     *
     * @return array<int, array<string, mixed>>
     */
    public function modelos(?string $de = null): array
    {
        return $this->t->pedir('GET', 'mensagens/modelos', null, $de !== null && $de !== '' ? ['de' => $de] : []);
    }

    /**
     * As mensagens da conta, da mais nova para a mais antiga, sem o texto.
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array{pagina?: int, paginas?: int, total?: int}}
     */
    public function listar(int $pagina = 1, int $porPagina = 25): array
    {
        return $this->t->pedir('GET', 'mensagens', null, ['page' => $pagina, 'per_page' => $porPagina], envelope: true);
    }

    /**
     * Uma mensagem, com o `texto` enviado. Depois de 30 dias o texto vem `null`.
     *
     * @return array<string, mixed>
     */
    public function obter(string $id): array
    {
        return $this->t->pedir('GET', 'mensagens/'.rawurlencode($id));
    }
}
