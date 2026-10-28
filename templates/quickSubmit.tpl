{**
 * plugins/generic/quickSubmitOmp/templates/quickSubmit.tpl
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * The quick submission page: the component of js/quickSubmitOmp.js, fed by the state
 * the handler sets.
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">
		{$pageTitle|escape}
	</h1>

	<div class="app__contentPanel">
		<quick-submit-omp :config="quickSubmitOmp"></quick-submit-omp>
	</div>
{/block}
