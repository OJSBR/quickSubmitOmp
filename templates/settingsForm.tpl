{**
 * plugins/generic/quickSubmitOmp/templates/settingsForm.tpl
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Settings of the quick submission: what a new contributor gets, and the user group of
 * each contributor role.
 *}
<script>
	$(function() {ldelim}
		$('#quickSubmitOmpSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form
	class="pkp_form"
	id="quickSubmitOmpSettingsForm"
	method="POST"
	action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}"
>
	{csrf}

	{fbvFormArea id="quickSubmitOmpDefaults" title="plugins.generic.quickSubmitOmp.settings.defaults"}
		{fbvFormSection label="plugins.generic.quickSubmitOmp.settings.defaultEmail" description="plugins.generic.quickSubmitOmp.settings.defaultEmail.description"}
			{fbvElement type="text" id="defaultEmail" value=$defaultEmail size=$fbvStyles.size.MEDIUM}
			<p class="description">{translate key="plugins.generic.quickSubmitOmp.settings.emailInUse" email=$emailInUse|escape}</p>
		{/fbvFormSection}
		{fbvFormSection label="plugins.generic.quickSubmitOmp.settings.defaultCountry" description="plugins.generic.quickSubmitOmp.settings.defaultCountry.description"}
			{fbvElement type="select" id="defaultCountry" from=$countries selected=$defaultCountry translate=false size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="quickSubmitOmpGroups" title="plugins.generic.quickSubmitOmp.settings.groups"}
		<p class="description">{translate key="plugins.generic.quickSubmitOmp.settings.groups.description"}</p>
		{fbvFormSection label="plugins.generic.quickSubmitOmp.role.volumeEditor"}
			{fbvElement type="select" id="groupVolumeEditor" from=$authorGroups selected=$groupVolumeEditor translate=false size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}
		{fbvFormSection label="plugins.generic.quickSubmitOmp.role.chapterAuthor"}
			{fbvElement type="select" id="groupChapterAuthor" from=$authorGroups selected=$groupChapterAuthor translate=false size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}
		{fbvFormSection label="plugins.generic.quickSubmitOmp.role.author"}
			{fbvElement type="select" id="groupAuthor" from=$authorGroups selected=$groupAuthor translate=false size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons submitText="common.save" hideCancel=true}
</form>
