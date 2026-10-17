<?php

namespace RadarData\Recursos;

use RadarData\Transporte;

/**
 * Seus proprios fluxos de analise. Escopo `fluxos`.
 *
 * E o jeito de montar o pipeline por codigo, em vez de desenha-lo no
 * painel: voce manda a definicao inteira (entrada, etapas, regras,
 * saidas) num array e recebe de volta um fluxo publicado, que ja roda
 * com `analises()->criar('<slug>', ...)`.
 *
 * O PRECO nao vai no pedido. Ele e calculado a partir das bases que o
 * fluxo consulta, com a tabela de preco da sua conta, e devolvido tanto
 * em `validar()` quanto em `publicar()`. Chame `validar()` antes de
 * publicar quando quiser ver o numero sem mexer em nada.
 *
 * O escopo e separado de `analises` de proposito: um token que so roda
 * analise no dia a dia nao consegue reescrever o pipeline que decide
 * sobre as pessoas da sua base. Use um token de provisionamento aqui.
 */
class Fluxos
{
    public function __construct(private readonly Transporte $t) {}

    /** @return array<int, array<string, mixed>> Os fluxos desta conta. */
    public function listar(): array
    {
        return $this->t->pedir('GET', 'analises/fluxos');
    }

    /** @return array<string, mixed> O fluxo, com a definicao publicada e o historico de versoes. */
    public function mostrar(string $slug): array
    {
        return $this->t->pedir('GET', 'analises/fluxos/'.rawurlencode($slug));
    }

    /**
     * Confere a definicao e devolve o preco, sem gravar nada.
     *
     * @param  array<string, mixed>  $definicao
     * @return array{valida: bool, preco: int, preco_por_consulta: array<string, int>, preco_incerto: bool}
     */
    public function validar(array $definicao): array
    {
        return $this->t->pedir('POST', 'analises/fluxos/validar', ['definicao' => $definicao]);
    }

    /**
     * Cria ou republica o fluxo.
     *
     * Idempotente pelo conteudo: mandar a mesma definicao de novo nao
     * cria versao nova, o que deixa chamar isto a cada deploy sem encher
     * o historico.
     *
     * @param  array<string, mixed>  $definicao  entrada, etapas, regras, saidas
     * @param  array<string, mixed>  $opcoes  descricao, motivo, exige_finalidade, finalidade_padrao
     * @return array<string, mixed>
     */
    public function publicar(string $slug, string $nome, string $alvo, array $definicao, array $opcoes = []): array
    {
        return $this->t->pedir('PUT', 'analises/fluxos/'.rawurlencode($slug), [
            'nome' => $nome,
            'alvo' => $alvo,
            'definicao' => $definicao,
        ] + $opcoes);
    }

    /**
     * Desliga o fluxo. As analises ja feitas continuam onde estao, com a
     * versao que rodou nelas.
     *
     * @return array<string, mixed>
     */
    public function remover(string $slug): array
    {
        return $this->t->pedir('DELETE', 'analises/fluxos/'.rawurlencode($slug));
    }
}
