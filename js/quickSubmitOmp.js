/**
 * @file plugins/generic/quickSubmitOmp/js/quickSubmitOmp.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * The quick submission page: the book, its contributors, its chapters and its final
 * formats on one page, sent at once to the plugin, which makes and publishes the book.
 *
 * The component is registered through pkp.registry and uses the field components of
 * the core, each one configured from a prototype the handler builds with the core
 * classes (texts, upload options); files go to the temporary files API as in the core
 * forms, and only their ids are sent with the book.
 */
(function () {
	'use strict';

	if (!window.pkp || !pkp.registry || !pkp.modules || !pkp.modules.vue) {
		return;
	}
	var vue = pkp.modules.vue;

	pkp.registry.registerComponent('QuickSubmitOmp', {
		name: 'QuickSubmitOmp',
		props: {
			config: {type: Object, required: true}
		},
		setup: function (props) {
			var c = props.config;
			var t = c.i18n;
			var counter = 0;
			var newKey = function (prefix) {
				counter++;
				return prefix + counter;
			};

			var book = vue.reactive({
				workType: c.workTypes[0].value,
				locale: c.primaryLocale,
				seriesId: '',
				seriesPosition: '',
				prefix: '',
				title: '',
				subtitle: '',
				abstract: '',
				keywords: '',
				coverImage: null,
				datePublished: c.today,
				publish: true
			});
			var contributors = vue.reactive([]);
			var chapters = vue.reactive([]);
			var formats = vue.reactive([]);
			var errors = vue.ref({});
			var saving = vue.ref(false);
			var result = vue.ref(null);

			var workType = vue.computed(function () {
				return c.workTypes.find(function (w) {
					return w.value === Number(book.workType);
				}) || c.workTypes[0];
			});
			var roleOptions = vue.computed(function () {
				return workType.value.roles.map(function (role) {
					return {value: role, label: c.roles[role]};
				});
			});
			var authorOptions = vue.computed(function () {
				return contributors.map(function (person) {
					var name = (person.givenName + ' ' + person.familyName).trim();
					return {value: person.key, label: name || '…'};
				});
			});

			function addContributor(role) {
				contributors.push({
					key: newKey('c'),
					givenName: '',
					familyName: '',
					role: role || roleOptions.value[0].value,
					email: c.defaultEmail,
					country: c.defaultCountry,
					affiliation: '',
					orcid: '',
					open: false
				});
			}
			function addChapter() {
				chapters.push({key: newKey('h'), title: '', subtitle: '', abstract: '', pages: '', authors: [], file: null});
			}
			function addFormat() {
				formats.push({key: newKey('f'), name: formats.length ? '' : 'PDF', isbn: '', file: null});
			}
			function remove(list, index) {
				list.splice(index, 1);
			}
			function move(list, index, step) {
				var target = index + step;
				if (target < 0 || target >= list.length) {
					return;
				}
				var item = list.splice(index, 1)[0];
				list.splice(target, 0, item);
			}

			// A contributor whose role does not exist in the new kind of book gets the first one.
			vue.watch(function () {
				return book.workType;
			}, function () {
				contributors.forEach(function (person) {
					if (!workType.value.roles.includes(person.role)) {
						person.role = workType.value.roles[0];
					}
				});
			});
			// A chapter never keeps a contributor who was removed.
			vue.watch(function () {
				return contributors.map(function (person) {
					return person.key;
				}).join();
			}, function () {
				var keys = contributors.map(function (person) {
					return person.key;
				});
				chapters.forEach(function (chapter) {
					chapter.authors = chapter.authors.filter(function (key) {
						return keys.includes(key);
					});
				});
			});

			addContributor();
			addChapter();
			addFormat();

			/**
			 * The props of a core field from its prototype. The name is the path of the
			 * value, with dashes, so that the errors of the plugin land on it.
			 */
			function field(prototype, name, value, extra) {
				return Object.assign({}, c.fields[prototype], {
					name: name,
					formId: 'quickSubmitOmp',
					value: value,
					allErrors: errors.value,
					isMultilingual: false,
					locales: [],
					primaryLocale: book.locale
				}, extra || {});
			}

			function setFieldErrors(name, list) {
				var all = Object.assign({}, errors.value);
				if (list && list.length) {
					all[name] = list;
				} else {
					delete all[name];
				}
				errors.value = all;
			}

			function groupErrors(name) {
				return errors.value[name] || [];
			}

			function payload() {
				return {
					workType: Number(book.workType),
					locale: book.locale,
					seriesId: book.seriesId ? Number(book.seriesId) : null,
					seriesPosition: book.seriesPosition,
					prefix: book.prefix,
					title: book.title,
					subtitle: book.subtitle,
					abstract: book.abstract,
					keywords: book.keywords,
					coverImage: book.coverImage,
					datePublished: book.datePublished,
					publish: book.publish,
					contributors: contributors.map(function (person) {
						return {
							key: person.key,
							givenName: person.givenName,
							familyName: person.familyName,
							role: person.role,
							email: person.email,
							country: person.country,
							affiliation: person.affiliation,
							orcid: person.orcid
						};
					}),
					chapters: chapters.map(function (chapter) {
						return {
							title: chapter.title,
							subtitle: chapter.subtitle,
							abstract: chapter.abstract,
							pages: chapter.pages,
							authors: chapter.authors.slice(),
							file: chapter.file
						};
					}),
					formats: formats.map(function (format) {
						return {name: format.name, isbn: format.isbn, file: format.file};
					})
				};
			}

			function save() {
				if (saving.value) {
					return;
				}
				saving.value = true;
				errors.value = {};
				fetch(c.saveUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {'Content-Type': 'application/json', 'X-Csrf-Token': pkp.currentUser.csrfToken},
					body: JSON.stringify(payload())
				}).then(function (response) {
					return response.json();
				}).then(function (json) {
					saving.value = false;
					if (json.status && json.content && json.content.submissionId) {
						result.value = json.content;
						window.scrollTo(0, 0);
						return;
					}
					var all = {};
					var serverErrors = (json.content && json.content.errors) || {save: [t.saveFailed]};
					Object.keys(serverErrors).forEach(function (path) {
						all[path.replace(/\./g, '-')] = serverErrors[path];
					});
					errors.value = all;
					// Open the extra details of a contributor whose hidden field has an error.
					contributors.forEach(function (person, i) {
						['email', 'country', 'affiliation', 'orcid'].forEach(function (name) {
							if (all['contributors-' + i + '-' + name]) {
								person.open = true;
							}
						});
					});
					vue.nextTick(function () {
						var first = document.querySelector('.quickSubmitOmp .pkpFormField__error, .quickSubmitOmp .quickSubmitOmp__error');
						if (first) {
							first.scrollIntoView({block: 'center'});
						}
					});
				}).catch(function () {
					saving.value = false;
					errors.value = {save: [t.saveFailed]};
				});
			}

			return {
				c: c,
				t: t,
				book: book,
				contributors: contributors,
				chapters: chapters,
				formats: formats,
				errors: errors,
				saving: saving,
				result: result,
				roleOptions: roleOptions,
				authorOptions: authorOptions,
				addContributor: addContributor,
				addChapter: addChapter,
				addFormat: addFormat,
				remove: remove,
				move: move,
				field: field,
				setFieldErrors: setFieldErrors,
				groupErrors: groupErrors,
				save: save,
				reload: function () {
					window.location.reload();
				}
			};
		},
		template: '' +
'<div class="quickSubmitOmp">' +
'  <div v-if="result" class="quickSubmitOmp__done" role="status">' +
'    <h2>{{ result.published ? t.saved : t.savedUnpublished }}</h2>' +
'    <p class="quickSubmitOmp__doneTitle">{{ result.title }}</p>' +
'    <p class="quickSubmitOmp__actions">' +
'      <PkpButton element="a" :href="result.bookUrl" :is-primary="true" v-if="result.published">{{ t.viewBook }}</PkpButton>' +
'      <PkpButton element="a" :href="result.workflowUrl">{{ t.viewWorkflow }}</PkpButton>' +
'      <PkpButton data-qso="another" @click="reload">{{ t.another }}</PkpButton>' +
'    </p>' +
'  </div>' +
'  <form v-else class="quickSubmitOmp__form" @submit.prevent="save" novalidate>' +
'    <p class="quickSubmitOmp__intro">{{ t.intro }}</p>' +

'    <section class="quickSubmitOmp__section" aria-labelledby="qso-book">' +
'      <h2 id="qso-book">{{ t.book }}</h2>' +
'      <PkpFieldOptions v-bind="field(\'options\', \'workType\', book.workType, {label: t.workType, type: \'radio\', options: c.workTypes.map(function (w) { return {value: w.value, label: w.label}; }), isRequired: true})" @change="function (n, p, v) { book.workType = v; }"></PkpFieldOptions>' +
'      <PkpFieldSelect v-if="c.locales.length > 1" v-bind="field(\'select\', \'locale\', book.locale, {label: t.locale, options: c.locales, isRequired: true})" @change="function (n, p, v) { book.locale = v; }"></PkpFieldSelect>' +
'      <div class="quickSubmitOmp__row" v-if="c.series.length">' +
'        <PkpFieldSelect v-bind="field(\'select\', \'seriesId\', book.seriesId, {label: t.series, options: [{value: \'\', label: t.seriesNone}].concat(c.series)})" @change="function (n, p, v) { book.seriesId = v; }"></PkpFieldSelect>' +
'        <PkpFieldText v-if="book.seriesId" v-bind="field(\'text\', \'seriesPosition\', book.seriesPosition, {label: t.seriesPosition, size: \'small\'})" @change="function (n, p, v) { book.seriesPosition = v; }"></PkpFieldText>' +
'      </div>' +
'      <div class="quickSubmitOmp__row">' +
'        <PkpFieldText v-bind="field(\'text\', \'prefix\', book.prefix, {label: t.prefix, size: \'small\'})" @change="function (n, p, v) { book.prefix = v; }"></PkpFieldText>' +
'        <PkpFieldText v-bind="field(\'text\', \'title\', book.title, {label: t.title, isRequired: true, size: \'large\'})" @change="function (n, p, v) { book.title = v; }"></PkpFieldText>' +
'      </div>' +
'      <PkpFieldText v-bind="field(\'text\', \'subtitle\', book.subtitle, {label: t.subtitle, size: \'large\'})" @change="function (n, p, v) { book.subtitle = v; }"></PkpFieldText>' +
'      <PkpFieldRichTextarea v-bind="field(\'richText\', \'abstract\', book.abstract, {label: t.abstract, size: \'large\'})" @change="function (n, p, v) { book.abstract = v; }"></PkpFieldRichTextarea>' +
'      <PkpFieldText v-bind="field(\'text\', \'keywords\', book.keywords, {label: t.keywords, description: t.keywordsDescription, size: \'large\'})" @change="function (n, p, v) { book.keywords = v; }"></PkpFieldText>' +
'      <PkpFieldUploadImage v-bind="field(\'image\', \'coverImage\', book.coverImage, {label: t.cover})" @change="function (n, p, v) { book.coverImage = v; }" @set-errors="setFieldErrors"></PkpFieldUploadImage>' +
'      <PkpFieldText v-bind="field(\'text\', \'datePublished\', book.datePublished, {label: t.datePublished, inputType: \'date\', size: \'small\'})" @change="function (n, p, v) { book.datePublished = v; }"></PkpFieldText>' +
'    </section>' +

'    <section class="quickSubmitOmp__section" aria-labelledby="qso-contributors">' +
'      <h2 id="qso-contributors">{{ t.contributors }}</h2>' +
'      <p class="quickSubmitOmp__description">{{ t.contributorsDescription }}</p>' +
'      <p v-for="error in groupErrors(\'contributors\')" class="quickSubmitOmp__error" role="alert">{{ error }}</p>' +
'      <div v-for="(person, i) in contributors" :key="person.key" class="quickSubmitOmp__card" :data-contributor="i">' +
'        <div class="quickSubmitOmp__row">' +
'          <PkpFieldText v-bind="field(\'text\', \'contributors-\' + i + \'-givenName\', person.givenName, {label: t.givenName, isRequired: true})" @change="function (n, p, v) { person.givenName = v; }"></PkpFieldText>' +
'          <PkpFieldText v-bind="field(\'text\', \'contributors-\' + i + \'-familyName\', person.familyName, {label: t.familyName, isRequired: true})" @change="function (n, p, v) { person.familyName = v; }"></PkpFieldText>' +
'          <PkpFieldSelect v-bind="field(\'select\', \'contributors-\' + i + \'-role\', person.role, {label: t.role, options: roleOptions, isRequired: true})" @change="function (n, p, v) { person.role = v; }"></PkpFieldSelect>' +
'        </div>' +
'        <div class="quickSubmitOmp__cardActions">' +
'          <PkpButton :is-link="true" :aria-expanded="person.open ? \'true\' : \'false\'" @click="person.open = !person.open">{{ t.moreDetails }}</PkpButton>' +
'          <PkpButton :is-link="true" @click="move(contributors, i, -1)" :is-disabled="i === 0">{{ t.moveUp }}</PkpButton>' +
'          <PkpButton :is-link="true" @click="move(contributors, i, 1)" :is-disabled="i === contributors.length - 1">{{ t.moveDown }}</PkpButton>' +
'          <PkpButton :is-warnable="true" @click="remove(contributors, i)">{{ t.remove }}</PkpButton>' +
'        </div>' +
'        <div v-show="person.open" class="quickSubmitOmp__more">' +
'          <div class="quickSubmitOmp__row">' +
'            <PkpFieldText v-bind="field(\'text\', \'contributors-\' + i + \'-email\', person.email, {label: t.email, inputType: \'email\'})" @change="function (n, p, v) { person.email = v; }"></PkpFieldText>' +
'            <PkpFieldSelect v-bind="field(\'select\', \'contributors-\' + i + \'-country\', person.country, {label: t.country, options: [{value: \'\', label: \'\'}].concat(c.countries)})" @change="function (n, p, v) { person.country = v; }"></PkpFieldSelect>' +
'          </div>' +
'          <div class="quickSubmitOmp__row">' +
'            <PkpFieldText v-bind="field(\'text\', \'contributors-\' + i + \'-affiliation\', person.affiliation, {label: t.affiliation, size: \'large\'})" @change="function (n, p, v) { person.affiliation = v; }"></PkpFieldText>' +
'            <PkpFieldText v-bind="field(\'text\', \'contributors-\' + i + \'-orcid\', person.orcid, {label: t.orcid})" @change="function (n, p, v) { person.orcid = v; }"></PkpFieldText>' +
'          </div>' +
'        </div>' +
'      </div>' +
'      <PkpButton data-qso="add-contributor" @click="addContributor()">{{ t.addContributor }}</PkpButton>' +
'    </section>' +

'    <section class="quickSubmitOmp__section" aria-labelledby="qso-chapters">' +
'      <h2 id="qso-chapters">{{ t.chapters }}</h2>' +
'      <p class="quickSubmitOmp__description">{{ t.chaptersDescription }}</p>' +
'      <div v-for="(chapter, i) in chapters" :key="chapter.key" class="quickSubmitOmp__card" :data-chapter="i">' +
'        <h3>{{ t.chapter.replace(\'{$number}\', i + 1) }}</h3>' +
'        <PkpFieldText v-bind="field(\'text\', \'chapters-\' + i + \'-title\', chapter.title, {label: t.title, isRequired: true, size: \'large\'})" @change="function (n, p, v) { chapter.title = v; }"></PkpFieldText>' +
'        <div class="quickSubmitOmp__row">' +
'          <PkpFieldText v-bind="field(\'text\', \'chapters-\' + i + \'-subtitle\', chapter.subtitle, {label: t.subtitle, size: \'large\'})" @change="function (n, p, v) { chapter.subtitle = v; }"></PkpFieldText>' +
'          <PkpFieldText v-bind="field(\'text\', \'chapters-\' + i + \'-pages\', chapter.pages, {label: t.pages, size: \'small\'})" @change="function (n, p, v) { chapter.pages = v; }"></PkpFieldText>' +
'        </div>' +
'        <PkpFieldOptions v-if="authorOptions.length" v-bind="field(\'options\', \'chapters-\' + i + \'-authors\', chapter.authors, {label: t.chapterAuthors, type: \'checkbox\', options: authorOptions})" @change="function (n, p, v) { chapter.authors = v; }"></PkpFieldOptions>' +
'        <p v-else class="quickSubmitOmp__description">{{ t.noContributorsYet }}</p>' +
'        <PkpFieldRichTextarea v-bind="field(\'richText\', \'chapters-\' + i + \'-abstract\', chapter.abstract, {label: t.abstract})" @change="function (n, p, v) { chapter.abstract = v; }"></PkpFieldRichTextarea>' +
'        <PkpFieldUpload v-bind="field(\'pdf\', \'chapters-\' + i + \'-file\', chapter.file, {label: t.chapterFile})" @change="function (n, p, v) { chapter.file = v; }" @set-errors="setFieldErrors"></PkpFieldUpload>' +
'        <div class="quickSubmitOmp__cardActions">' +
'          <PkpButton :is-link="true" @click="move(chapters, i, -1)" :is-disabled="i === 0">{{ t.moveUp }}</PkpButton>' +
'          <PkpButton :is-link="true" @click="move(chapters, i, 1)" :is-disabled="i === chapters.length - 1">{{ t.moveDown }}</PkpButton>' +
'          <PkpButton :is-warnable="true" @click="remove(chapters, i)">{{ t.remove }}</PkpButton>' +
'        </div>' +
'      </div>' +
'      <PkpButton data-qso="add-chapter" @click="addChapter()">{{ t.addChapter }}</PkpButton>' +
'    </section>' +

'    <section class="quickSubmitOmp__section" aria-labelledby="qso-formats">' +
'      <h2 id="qso-formats">{{ t.formats }}</h2>' +
'      <p class="quickSubmitOmp__description">{{ t.formatsDescription }}</p>' +
'      <div v-for="(format, i) in formats" :key="format.key" class="quickSubmitOmp__card" :data-format="i">' +
'        <div class="quickSubmitOmp__row">' +
'          <PkpFieldText v-bind="field(\'text\', \'formats-\' + i + \'-name\', format.name, {label: t.formatName, isRequired: true})" @change="function (n, p, v) { format.name = v; }"></PkpFieldText>' +
'          <PkpFieldText v-bind="field(\'text\', \'formats-\' + i + \'-isbn\', format.isbn, {label: t.isbn})" @change="function (n, p, v) { format.isbn = v; }"></PkpFieldText>' +
'        </div>' +
'        <PkpFieldUpload v-bind="field(\'file\', \'formats-\' + i + \'-file\', format.file, {label: t.formatFile, isRequired: true})" @change="function (n, p, v) { format.file = v; }" @set-errors="setFieldErrors"></PkpFieldUpload>' +
'        <div class="quickSubmitOmp__cardActions">' +
'          <PkpButton :is-warnable="true" @click="remove(formats, i)">{{ t.remove }}</PkpButton>' +
'        </div>' +
'      </div>' +
'      <PkpButton data-qso="add-format" @click="addFormat()">{{ t.addFormat }}</PkpButton>' +
'    </section>' +

'    <section class="quickSubmitOmp__section quickSubmitOmp__finish">' +
'      <PkpFieldOptions v-bind="field(\'options\', \'publish\', book.publish ? [\'publish\'] : [], {label: t.publishNow, description: t.publishNowDescription, type: \'checkbox\', options: [{value: \'publish\', label: t.publishNow}]})" @change="function (n, p, v) { book.publish = v.length > 0; }"></PkpFieldOptions>' +
'      <p v-for="error in groupErrors(\'publish\').concat(groupErrors(\'save\'))" class="quickSubmitOmp__error" role="alert">{{ error }}</p>' +
'      <p v-if="Object.keys(errors).length" class="quickSubmitOmp__error" role="alert">{{ t.errorsFound }}</p>' +
'      <PkpButton data-qso="save" :is-primary="true" :is-disabled="saving" @click="save">{{ saving ? t.saving : t.save }}</PkpButton>' +
'    </section>' +
'  </form>' +
'</div>'
	});
})();
