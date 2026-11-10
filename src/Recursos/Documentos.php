<?php

namespace RadarData\Recursos;

use RadarData\Transporte;

/**
 * Documentos enviados para o Motor de Analises. Escopo `analises`.
 *
 * O documento sobe ANTES da analise: o `id` devolvido aqui vai no campo de
 * documentos da entrada, como `['id' => ..., 'tipo' => ...]`. Documento que
 * nao entra em analise nenhuma expira em 24 horas.
 *
 * Sem idempotencia na api, entao o SDK NAO repete o envio: repetir as cegas
 * deixaria um segundo documento ocupando a cota de pendentes da conta.
 */
class Documentos
{
    /** Os tipos que a api aceita. */
    public const TIPOS = ['identidade', 'endereco', 'contrato_social', 'procuracao', 'cartao_cnpj', 'outro'];

    public function __construct(private readonly Transporte $t) {}

    /**
     * Envia um JPEG, PNG ou PDF de ate 10 MB.
     *
     * @param  string|resource  $arquivo  caminho do arquivo ou um recurso ja aberto
     * @param  string|null  $tipo  um de `self::TIPOS`
     * @return array<string, mixed> `id`, `tipo`, `tamanho` e `expira_em`
     */
    public function enviar(mixed $arquivo, ?string $tipo = null, ?string $nomeArquivo = null): array
    {
        if (is_string($arquivo)) {
            $nomeArquivo ??= basename($arquivo);
            $conteudo = @fopen($arquivo, 'r');
            if ($conteudo === false) {
                throw new \InvalidArgumentException("Não foi possível abrir o arquivo {$arquivo}.");
            }
        } elseif (is_resource($arquivo)) {
            $conteudo = $arquivo;
        } else {
            throw new \InvalidArgumentException('Passe o caminho do arquivo ou um recurso aberto.');
        }

        $partes = [['name' => 'arquivo', 'contents' => $conteudo, 'filename' => $nomeArquivo ?? 'documento']];
        if ($tipo !== null) {
            $partes[] = ['name' => 'tipo', 'contents' => $tipo];
        }

        return $this->t->pedir('POST', 'documentos', multipart: $partes);
    }
}
