# oneFilePHPGallery

Galeria de imagens em **arquivo único PHP** (compatível com **PHP 8.0**) que lê as imagens do próprio diretório e oferece:

- Paginação.
- Thumbnails.
- Visualização ampliada ao clicar na imagem (ajustada à tela).
- Navegação na visualização ampliada com setas esquerda/direita do teclado.
- Filtro por nome.
- Ordenação por nome, data e tipo de arquivo.
- Ações individuais: renomear e excluir.
- Ações em massa: excluir, adicionar prefixo, adicionar sufixo e mover selecionadas para uma nova pasta.
- Configuração de imagens por linha e por página.

## Uso

1. Coloque o `index.php` em um diretório com suas imagens.
2. Credenciais de acesso:
   - Usuário: `henrique`
   - Senha: `chegue1`
3. Rode com PHP embutido:

```bash
php -S 0.0.0.0:8000
```

4. Acesse `http://localhost:8000/index.php`.

## Extensões suportadas

`jpg`, `jpeg`, `png`, `gif`, `webp`, `bmp`, `avif`.
