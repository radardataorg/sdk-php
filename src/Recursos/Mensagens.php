<?php

namespace RadarData\Recursos;

use RadarData\Transporte;

/**
 * Mensagens: SMS hoje, WhatsApp, Instagram e Messenger no mesmo metodo depois, mudando so
 * o `canal`. Escopo `mensagens`, que NAO vem ligado por padrao no token. GASTA SALDO por
 * mensagem aceita; o que falha volta para o saldo.
 *
 * No SMS o texto sai no alfabeto padrao das operadoras: acento sai antes do envio, e
 * emoji recusa a mensagem (`EntradaInvalida`, codigo `texto_invalido`), sem cobrar.
 *
 * `aceita` quer dizer que saiu para a operadora, nao que chegou no aparelho. O aviso
 * chega pelos webhooks `mensagem.aceita` e `mensagem.falhou`.
 */
class Mensagens
{
    public function __construct(private readonly Transporte $t) {}

    /**
     * Manda uma mensagem. `$para` e um celular brasileiro (`+5511999999999`,
     * `11999999999` ou `(11) 99999-9999`), e `$texto` tem ate 160 caracteres.
     *
     * A chave de idempotencia e GERADA quando voce nao passa uma, e e ela que torna seguro
     * repetir: a mesma chave devolve a primeira mensagem, sem mandar nem cobrar de novo, e
     * a de um envio que falhou devolve o mesmo erro. Para codigo de acesso, passe uma chave
     * sua ligada ao pedido (`pedido-48213-codigo`), de 1 a 80 caracteres.
     *
     * Erro `envio_incerto` (`ErroDoServidor`, 504): nao confirmamos se saiu, e nao foi
     * cobrado. Reenviar com chave nova pode fazer a pessoa receber duas.
     *
     * @return array{mensagem: array<string, mixed>, meta: array{saldo?: int, preco?: int, sandbox?: bool}}
     */
    public function enviar(string $para, string $texto, ?string $chaveIdempotencia = null, string $canal = 'sms'): array
    {
        $r = $this->t->pedir(
            'POST',
            'mensagens',
            ['canal' => $canal, 'para' => $para, 'texto' => $texto],
            chaveIdempotencia: $chaveIdempotencia ?: Consultas::chave(),
            envelope: true,
        );

        return ['mensagem' => $r['data'] ?? [], 'meta' => $r['meta'] ?? []];
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
