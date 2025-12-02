{* Template customizado para páginas da área do cliente *}

{if isset($RSThemes['pages'][$templatefile]) && file_exists($RSThemes['pages'][$templatefile]['fullPath'])}
    {include file=$RSThemes['pages'][$templatefile]['fullPath']}
{else}
    <div class="container">
        {* Renderizar qualquer conteúdo HTML customizado *}
        {if $content}
            {$content}
        {elseif $pagecontent}
            {$pagecontent}
        {elseif $pageContent}
            {$pageContent}
        {elseif $body}
            {$body}
        {elseif $output}
            {$output}
        {elseif $hookOutput}
            {$hookOutput}
        {else}
            <div class="alert alert-info">
                Conteúdo não disponível.
            </div>
        {/if}
    </div>
{/if}

