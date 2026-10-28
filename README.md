# Quick Submission for OMP

[![OMP](https://img.shields.io/badge/OMP-3.5-brightgreen)](https://pkp.sfu.ca/omp/)
[![Version](https://img.shields.io/badge/version-1.0.0.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OMP 3.5](https://github.com/OJSBR/quickSubmitOmp/releases/download/1.0.0.0/quickSubmitOmp-1.0.0.0.tar.gz) — or browse all [Releases](../../releases).

A generic plugin for **Open Monograph Press (OMP) 3.5** that adds a whole book on one page —
the book, its contributors, its chapters with the PDF of each one and the final formats of the
book — and publishes it at once, with every format and file approved and in open access. It is
made for presses that publish books already edited and typeset, above all **edited volumes**.

> **Developed and maintained by [OJSBR](https://ojsbr.com).** See the
> [Credits & authorship](#credits--authorship) section below.

## Compatibility & branches

| Application | Branch | Plugin release |
|-------------|--------|----------------|
| OMP 3.5.x   | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.0.0.0 |

Requires PHP 8.2 or later.

## The problem

OMP has no quick submission: a book that is already finished goes through the submission wizard,
the workflow stages, the chapter grid, the publication formats grid and the approval of each file,
one by one. For an edited volume there is more: OMP names every contributor of the publication in
the book metadata it serves (Google Scholar and Dublin Core tags, OAI, ONIX), so the authors of
each chapter show up as authors of the whole book.

## What the plugin does

**Quick Submission**, a page in the backend menu of the managers of the press:

- **Book:** kind of book (edited volume or monograph), series and position, language, prefix,
  title, subtitle, abstract (rich text), keywords, cover and date of publication.
- **Volume editors and authors:** only the given name and the family name are required. The
  e-mail is the technical support contact of the press (or its principal contact when there is
  none) and the country is the press's; both, the affiliation and the ORCID iD can be changed
  under *More details*. In an edited volume each person is a *volume editor* or a *chapter
  author*; in a monograph, an *author*.
- **Chapters:** title, subtitle, abstract, pages, the authors of the chapter (chosen among the
  contributors) and the final PDF of the chapter. Each chapter gets its own page.
- **Final formats of the book:** one per file of the whole book (PDF, EPUB…), with its ISBN-13.
- **Publish now** (checked by default): the book goes to the production stage and is published;
  unchecked, it waits in production.

Everything is made the way the workflow forms make it, through the repositories of OMP:

- every publication format is **approved and available**, and every file is an **approved proof
  in open access** (price 0) — the book can be read at once;
- the PDF of a chapter is a file of the format named *PDF* (made when there is none), linked to
  its chapter;
- volume editors are marked as volume editors and appear in the catalog; chapter authors appear in
  their chapters only;
- the cover, keywords, license and copyright follow the core (the license and copyright of the
  press are set when the book is published);
- when anything fails, the half-made book is deleted: there is never a book half made.

**Edited volumes: the volume editors only in the book metadata.** For every edited volume of the
press with volume editors — made by the quick submission or not — the plugin rewrites:

- the **Google Scholar** (`citation_author`, `citation_author_institution`) and **Dublin Core**
  (`DC.Creator.PersonalName`) tags of the book page;
- the `dc:creator` of the **OAI** records;
- the contributors of the products in the **ONIX** export.

The chapter pages keep the authors of the chapter. The book page itself, the catalog and the
Crossref deposit of [OJSBR/crossref](https://github.com/OJSBR/crossref) already name the volume
editors once the contributors are marked as above. The *How to cite* block follows the roles set in
the Citation Style Language plugin: check that the volume editor role is in its *editors* list.

## Installation

1. Install via **Settings → Website → Plugins → Upload A New Plugin**, or extract the folder into
   `plugins/generic/` so you get `plugins/generic/quickSubmitOmp/`.
2. Enable **Quick Submission for OMP** under the *Generic* plugins list.
3. Reload the backend: **Quick Submission** appears in the menu of managers and site
   administrators.

## Settings

In the plugin **Settings**, per press:

- the **e-mail** and the **country** a new contributor gets (empty: the press's, as above);
- the **author role** of each kind of contributor (volume editor, chapter author, author). By
  default they are the roles of the press named as in OMP, so a press that renamed or added roles
  can choose them here.

## Tests

- **PHPUnit** (`tests/*Test.php`, on `PKP\tests\PKPTestCase`): what the page sends brought to one
  shape; the checks of each field (the names only required, the e-mail and country of the press,
  the roles of each kind of book, chapters, formats, ISBN-13, ORCID iD, dates, files of the user);
  the defaults of the press; the Google Scholar and Dublin Core tags, the OAI record and the ONIX
  products of an edited volume rewritten from its volume editors (and left alone otherwise); the
  plugin classes against the installed PKP; the templates and the 38 translations. From the OMP
  root:

  ```bash
  lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml --no-coverage "$PWD/plugins/generic/quickSubmitOmp/tests"
  ```

- **Cypress** (`cypress/tests/functional/QuickSubmitOmp.cy.js`, run by
  [pkp-github-actions](https://github.com/pkp/pkp-github-actions) on OMP, MySQL and PostgreSQL, on
  every push): opens the page from the menu; an empty book is refused with each field pointed out;
  an edited volume is filled in through the page — two contributors, a chapter with its author and
  its PDF, the PDF of the whole book — saved and published; the contributors are read back from the
  API as volume editor and chapter author; the book page names the volume editor only, the chapter
  page its author, and the PDF of the chapter downloads without signing in; as a counter-check,
  with the plugin off the book page names the chapter author too. The book is deleted at the end.
- Verified on OMP 3.5.0.5 with an edited volume made on the real database — two volume editors,
  two chapter authors, three chapters, a PDF and an EPUB of the whole book — read back table by
  table (submission, publication, authors and their settings, chapters and their authors, formats,
  ISBN, files of each format and chapter), the files downloaded without signing in, and the Google
  Scholar, Dublin Core, OAI and ONIX metadata with the plugin on and off.

Tests are kept in the repository and are not part of the release package.

## Credits & authorship

- **Developed and maintained by** [OJSBR](https://ojsbr.com) — original plugin.
- Distributed under the **GNU GPL v3**.

## AI use

Generative AI (Claude, by Anthropic) was used to write and run tests, improve the code and bring
it in line with PKP standards. Every change is reviewed and tested by OJSBR, which is responsible
for the published releases.

## Contributing

Issues and pull requests are welcome. See [`CONTRIBUTING.md`](CONTRIBUTING.md).

## License

Distributed under the **GNU GPL v3**. See [`LICENSE`](LICENSE) and `docs/COPYING`.

---

## 🇧🇷 Português

Plugin genérico para o **Open Monograph Press (OMP) 3.5** que cadastra o livro inteiro numa
página só — o livro, os colaboradores, os capítulos com o PDF de cada um e as composições finais
do livro — e publica de uma vez, com todos os formatos e arquivos aprovados e em acesso aberto.
Feito para editoras que publicam livros já editados e diagramados, sobretudo **livros
organizados**.

**⬇️ Pacote de instalação:** [OMP 3.5](https://github.com/OJSBR/quickSubmitOmp/releases/download/1.0.0.0/quickSubmitOmp-1.0.0.0.tar.gz).

> **Desenvolvido e mantido pela [OJSBR](https://ojsbr.com).** Veja a seção
> [Créditos e autoria](#créditos-e-autoria) abaixo.

### Compatibilidade

| Aplicação | Branch | Release do plugin |
|-----------|--------|-------------------|
| OMP 3.5.x | [`stable-3_5_0`](../../tree/stable-3_5_0) *(padrão)* | 1.0.0.0 |

Requer PHP 8.2 ou mais novo.

### O problema

O OMP não tem submissão rápida: um livro pronto passa pelo assistente de submissão, pelas etapas
do fluxo, pela grade de capítulos, pela grade de formatos e pela aprovação de cada arquivo, um por
um. No livro organizado há mais: o OMP põe todos os colaboradores da publicação nos metadados do
livro (tags do Google Scholar e do Dublin Core, OAI, ONIX), e os autores de cada capítulo aparecem
como autores do livro inteiro.

### O que o plugin faz

**Submissão rápida**, uma página no menu do painel dos gestores da editora:

- **Livro:** tipo (livro organizado ou monografia), série e posição, idioma, prefixo, título,
  subtítulo, resumo (texto rico), palavras-chave, capa e data de publicação.
- **Organizadores e autores:** só o nome e o sobrenome são obrigatórios. O e-mail é o contato de
  suporte técnico da editora (ou o contato principal, quando não houver) e o país é o da editora;
  os dois, a afiliação e o ORCID iD podem ser trocados em *Mais dados*. No livro organizado cada
  pessoa é *organizador(a)* ou *autor(a) de capítulo*; na monografia, *autor(a)*.
- **Capítulos:** título, subtítulo, resumo, páginas, os autores do capítulo (escolhidos entre os
  colaboradores) e o PDF da composição final do capítulo. Cada capítulo ganha página própria.
- **Composições finais do livro:** uma por arquivo do livro inteiro (PDF, EPUB…), com o ISBN-13.
- **Publicar agora** (marcado por padrão): o livro vai para a editoração e é publicado;
  desmarcado, fica esperando na editoração.

Tudo é feito como os formulários do fluxo fazem, pelos repositórios do OMP:

- todo formato fica **aprovado e disponível**, e todo arquivo é uma **prova aprovada em acesso
  aberto** (preço 0) — o livro pode ser lido na hora;
- o PDF do capítulo é arquivo do formato chamado *PDF* (criado quando não houver), ligado ao seu
  capítulo;
- os organizadores ficam marcados como organizadores e aparecem no catálogo; os autores de capítulo
  aparecem só nos seus capítulos;
- capa, palavras-chave, licença e direitos autorais seguem o núcleo (a licença e os direitos da
  editora entram quando o livro é publicado);
- se algo falhar, o livro feito pela metade é apagado: nunca sobra livro pela metade.

**Livro organizado: só os organizadores nos metadados do livro.** Em todo livro organizado da
editora que tenha organizadores — feito pela submissão rápida ou não — o plugin reescreve:

- as tags do **Google Scholar** (`citation_author`, `citation_author_institution`) e do **Dublin
  Core** (`DC.Creator.PersonalName`) da página do livro;
- o `dc:creator` dos registros **OAI**;
- os colaboradores dos produtos na exportação **ONIX**.

As páginas dos capítulos continuam com os autores do capítulo. A própria página do livro, o
catálogo e o depósito no Crossref do [OJSBR/crossref](https://github.com/OJSBR/crossref) já trazem
os organizadores quando os colaboradores estão marcados como acima. O bloco *Como citar* segue os
papéis definidos no plugin Citation Style Language: confira que o papel de organizador está na
lista de *editores* dele.

### Instalação

1. Instale em **Configurações → Website → Plugins → Enviar um novo plugin**, ou extraia a pasta em
   `plugins/generic/` (ficando `plugins/generic/quickSubmitOmp/`).
2. Ative o **Submissão Rápida para OMP** na lista de plugins *Genéricos*.
3. Recarregue o painel: **Submissão rápida** aparece no menu dos gestores e dos administradores do
   site.

### Configurações

Nas **Configurações** do plugin, por editora:

- o **e-mail** e o **país** que o colaborador novo recebe (em branco: os da editora, como acima);
- o **papel de autor** de cada tipo de colaborador (organizador, autor de capítulo, autor). Por
  padrão são os papéis da editora com o nome do OMP; a editora que renomeou ou criou papéis escolhe
  aqui.

### Testes

PHPUnit em `tests/` (sobre `PKP\tests\PKPTestCase`) e Cypress em `cypress/tests/functional/`
(rodado pelo [pkp-github-actions](https://github.com/pkp/pkp-github-actions) no OMP, em MySQL e
PostgreSQL, a cada push), com os comandos da seção em inglês. O PHPUnit cobre a leitura do que a
página envia, a conferência de cada campo, os padrões da editora, as tags do Google Scholar e do
Dublin Core, o registro OAI e os produtos ONIX do livro organizado reescritos a partir dos
organizadores (e intocados nos demais casos), as classes contra o PKP instalado, os templates e as
38 traduções. O Cypress faz o caminho pela página: abre pelo menu, vê o livro vazio recusado com
cada campo apontado, preenche um livro organizado — dois colaboradores, um capítulo com seu autor e
seu PDF, o PDF do livro inteiro —, salva e publica, relê os colaboradores pela API, confere que a
página do livro traz só o organizador, a do capítulo traz o autor dele e o PDF baixa sem login, e
faz a contraprova: com o plugin desligado, a página do livro traz também o autor do capítulo. No
fim, o livro é apagado. Verificado no OMP 3.5.0.5 com um livro organizado criado no banco real —
dois organizadores, dois autores de capítulo, três capítulos, PDF e EPUB do livro inteiro — relido
tabela por tabela, com os arquivos baixados sem login e os metadados do Google Scholar, do Dublin
Core, do OAI e do ONIX conferidos com o plugin ligado e desligado.

Os testes ficam no repositório e não fazem parte do pacote da release.

### Créditos e autoria

- **Desenvolvido e mantido pela** [OJSBR](https://ojsbr.com) — plugin autoral.
- Distribuído sob a **GNU GPL v3**.

### Uso de IA

Foi usada IA generativa (Claude, da Anthropic) para escrever e rodar testes, melhorar o código e
alinhá-lo aos padrões da PKP. Toda mudança é revisada e testada pela OJSBR, que responde pelas
releases publicadas.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE) e `docs/COPYING`.
