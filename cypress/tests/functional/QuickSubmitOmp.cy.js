/**
 * @file cypress/tests/functional/QuickSubmitOmp.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests of the quick submission, through the page: an empty book is refused
 * with each field pointed out; an edited volume with two contributors, a chapter with
 * its PDF and a PDF of the whole book is made and published; the files are open to
 * anybody; the book metadata names the volume editor only and the chapter its author;
 * and, as a counter-check, with the plugin off the book metadata names the chapter
 * author too. The book is deleted at the end.
 *
 * Parameters (--env): contextPath, adminUser, adminPassword (captcha on login must be off
 * for the run). The defaults match the data set of PKP's continuous integration, and the
 * first test enables the plugin when it is off.
 */

describe('Quick Submission for OMP', function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';
	const rowName = 'quicksubmitompplugin';
	const title = 'Quick submission test ' + Date.now();
	let made = null;

	// ---- OJSBR spec helpers (padrão v2): work on OJS/OMP 3.3, 3.4 and 3.5 and in PKP's CI ----

	const pageUrl = (path) => '/index.php/' + contextPath + (path ? '/' + path : '');

	// Same as PKP's cy.waitJQuery(), which the support files of OJS 3.3 test sites may lack.
	// The Plugins tab can keep requests open for a while (the plugin gallery), hence the timeout.
	const waitJQuery = () => cy.window().its('jQuery.active', {timeout: 60000}).should('eq', 0);

	// Requests carry the browser's User-Agent: OJS 3.3 drops a session whose agent changes.
	const request = (options) => cy.window({log: false}).then((win) => cy.request(Object.assign(
		typeof options === 'string' ? {url: options} : options,
		{headers: Object.assign({'User-Agent': win.navigator.userAgent}, (typeof options === 'string' ? {} : options.headers) || {})}
	)));

	// Signs in through requests (the login page can re-render while it is typed into), then
	// falls back to the form when the session did not stick (OJS 3.3 cookie handling).
	const login = (username, password) => {
		cy.clearCookies();
		request(pageUrl('login')).then((response) => {
			const token = /name="csrfToken" value="([^"]+)"/.exec(response.body)[1];
			// The form posts to the URL with the language: a redirect would turn the POST into a GET.
			const action = /<form[^>]*id="login"[^>]*action="([^"]+)"/.exec(response.body)[1];
			request({method: 'POST', url: action, form: true, body: {csrfToken: token, username: username, password: password}, log: false});
		});
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.get('body').then(($body) => {
			if ($body.find('form#login').length) {
				cy.get('form#login input[name="username"]').type(username, {delay: 0});
				cy.get('form#login input[name="password"]').type(password, {delay: 0, log: false});
				cy.get('form#login').submit();
				cy.get('form#login', {timeout: 30000}).should('not.exist');
			}
		});
	};

	// REST API calls made from the page itself, so they carry the browser's own session.
	const api = (path, options = {}) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, Object.assign({credentials: 'same-origin'}, options)).then((response) => {
			if (!response.ok) {
				return response.text().then((text) => {
					throw new Error(path + ' answered ' + response.status + ': ' + text.slice(0, 300));
				});
			}
			return response.json();
		}),
		{log: false, timeout: 30000}
	));

	// The website settings page on its Plugins tab (a new query string forces a load). Load it
	// once per test: loading it again while its plugin gallery request is pending stalls the
	// web server of PKP's CI; API calls and settings modals work on the page already open.
	const openPluginsTab = () => {
		cy.visit(pageUrl('management/settings/website') + '?reload=' + Date.now() + '#plugins');
		cy.get('button[id="plugins-button"]', {timeout: 60000}).click();
		cy.get('button[id="plugins-button"]').should('have.attr', 'aria-selected', 'true');
		waitJQuery();
	};

	// Enables the plugin in the grid when it is off (never turns it off).
	const enablePlugin = (rowName) => {
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]', {timeout: 30000}).then(($checkbox) => {
			if (!$checkbox.is(':checked')) {
				cy.wrap($checkbox).click();
				waitJQuery();
			}
		});
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]').should('be.checked');
	};

	// Opens the settings modal from the grid, without reloading the page: a reload right
	// after saving can stall the web server of PKP's CI. The form is fetched each time.
	const openPluginSettings = (rowName, formSelector) => {
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]', {timeout: 30000}).then(($link) => {
			if (!$link.is(':visible')) {
				cy.get('tr[id$="-row-' + rowName + '"] a.show_extras').first().click();
			}
		});
		// The grid may still be animating the extras row: the link is clicked once it exists.
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]').first().click({force: true});
		waitJQuery();
		cy.window().should((win) => {
			expect(win.jQuery(formSelector).data('pkp.handler')).to.exist;
		});
	};

	// ---- end of helpers ----


	const page = () => {
		cy.visit(pageUrl('quickSubmitOmp') + '?reload=' + Date.now());
		cy.get('.quickSubmitOmp form', {timeout: 60000}).should('be.visible');
	};

	const field = (name) => '.quickSubmitOmp [name="' + name + '"]';

	// A file on a core upload field: the dropzone keeps its input inside the control.
	const upload = (name, fixture, mimeType) => {
		cy.intercept('POST', '**/temporaryFiles').as('temporaryFile');
		cy.fixture(fixture, 'base64').then((fileContent) => {
			cy.get('[id="quickSubmitOmp-' + name + '-control"] input[type=file]').attachFile({
				fileContent: fileContent,
				encoding: 'base64',
				filePath: name + '.pdf',
				mimeType: mimeType,
			});
		});
		cy.wait('@temporaryFile').its('response.statusCode').should('eq', 200);
	};

	const metaTags = (html, name) => {
		const found = [];
		const pattern = new RegExp('<meta name="' + name.replace('.', '\\.') + '" content="([^"]*)"', 'g');
		let match;
		while ((match = pattern.exec(html))) {
			found.push(match[1]);
		}
		return found;
	};

	it('Enables the plugin and opens the page from the menu', function() {
		login(adminUser, adminPassword);
		openPluginsTab();
		enablePlugin(rowName);
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.get('a[href*="/quickSubmitOmp"]', {timeout: 60000}).first().click();
		cy.get('.quickSubmitOmp form', {timeout: 60000}).should('be.visible');
	});

	it('Refuses an empty book and points out each field', function() {
		login(adminUser, adminPassword);
		page();
		cy.intercept('POST', '**/quickSubmitOmp/save').as('save');
		cy.get('[data-qso="save"]').click();
		cy.wait('@save').then((interception) => {
			const content = interception.response.body.content;
			expect(interception.response.body.status).to.eq(false);
			expect(content.errors).to.include.keys('title', 'contributors.0.givenName', 'contributors.0.familyName', 'chapters.0.title', 'formats.0.file');
		});
		cy.get('.quickSubmitOmp .quickSubmitOmp__error').should('be.visible');
		cy.get('[id="quickSubmitOmp-title-error"], .quickSubmitOmp .pkpFormField__error').should('exist');
	});

	it('Makes and publishes an edited volume with a chapter and its PDF', function() {
		login(adminUser, adminPassword);
		page();
		cy.get(field('workType')).first().check();
		cy.get(field('title')).type(title, {delay: 0});
		cy.setTinyMceContent('quickSubmitOmp-abstract-control', 'An abstract of the book.');
		cy.get(field('keywords')).type('quick, submission', {delay: 0});

		cy.get(field('contributors-0-givenName')).type('Vera', {delay: 0});
		cy.get(field('contributors-0-familyName')).type('Editor', {delay: 0});
		cy.get(field('contributors-0-role')).should('have.value', 'volumeEditor');
		cy.get('[data-qso="add-contributor"]').click();
		cy.get(field('contributors-1-givenName')).type('Carl', {delay: 0});
		cy.get(field('contributors-1-familyName')).type('Chapter', {delay: 0});
		cy.get(field('contributors-1-role')).select('chapterAuthor');

		cy.get(field('chapters-0-title')).type('The first chapter', {delay: 0});
		cy.get('.quickSubmitOmp [data-chapter="0"] input[type="checkbox"]').eq(1).check();
		upload('chapters-0-file', 'dummy.pdf', 'application/pdf');

		cy.get(field('formats-0-name')).should('have.value', 'PDF');
		upload('formats-0-file', 'dummy.pdf', 'application/pdf');

		cy.intercept('POST', '**/quickSubmitOmp/save').as('save');
		cy.get('[data-qso="save"]').click();
		cy.wait('@save', {timeout: 60000}).then((interception) => {
			expect(interception.response.body.status, JSON.stringify(interception.response.body)).to.eq(true);
			made = interception.response.body.content;
			expect(made.published).to.eq(true);
		});
		cy.get('.quickSubmitOmp__done').should('be.visible').and('contain', title);
	});

	it('Saves the contributors as volume editor and chapter author', function() {
		login(adminUser, adminPassword);
		api(pageUrl('api/v1/submissions/' + made.submissionId)).then((submission) => {
			expect(submission.status).to.eq(3);
			expect(submission.stageId).to.eq(5);
			const publication = submission.publications[submission.publications.length - 1];
			api(pageUrl('api/v1/submissions/' + made.submissionId + '/publications/' + publication.id)).then((full) => {
				const people = full.authors.map((author) => [author.givenName.en, author.isVolumeEditor, author.includeInBrowse]);
				expect(people).to.deep.equal([['Vera', true, true], ['Carl', false, false]]);
			});
		});
	});

	it('Names the volume editor in the book and the author in the chapter, with the PDF open to anybody', function() {
		cy.clearCookies();
		request(made.bookUrl).then((response) => {
			expect(metaTags(response.body, 'citation_author')).to.deep.equal(['Vera Editor']);
			expect(metaTags(response.body, 'DC.Creator.PersonalName')).to.deep.equal(['Vera Editor']);
			const chapterUrl = /href="([^"]*\/chapter\/\d+)"/.exec(response.body)[1];
			request(chapterUrl).then((chapter) => {
				expect(metaTags(chapter.body, 'citation_author')).to.deep.equal(['Carl Chapter']);
				const viewUrl = /href="([^"]*\/catalog\/view\/\d+\/\d+\/\d+)"/.exec(chapter.body)[1];
				request({url: viewUrl.replace('/catalog/view/', '/catalog/download/'), encoding: 'binary'}).then((file) => {
					expect(file.status).to.eq(200);
					expect(file.headers['content-type']).to.contain('application/pdf');
					expect(file.body.slice(0, 5)).to.eq('%PDF-');
				});
			});
		});
	});

	it('Counter-check: with the plugin off the book names the chapter author too', function() {
		login(adminUser, adminPassword);
		openPluginsTab();
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]').uncheck();
		cy.get('[role="dialog"] button', {timeout: 30000}).contains(/^\s*OK\s*$/).click();
		waitJQuery();
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]').should('not.be.checked');
		cy.clearCookies();
		request(made.bookUrl + '?off=' + Date.now()).then((response) => {
			expect(metaTags(response.body, 'citation_author')).to.deep.equal(['Vera Editor', 'Carl Chapter']);
		});
		login(adminUser, adminPassword);
		openPluginsTab();
		enablePlugin(rowName);
	});

	it('Deletes the book', function() {
		if (!made) {
			return;
		}
		login(adminUser, adminPassword);
		cy.window().then((win) => {
			const headers = {'X-Csrf-Token': win.pkp.currentUser.csrfToken};
			api(pageUrl('api/v1/submissions/' + made.submissionId)).then((submission) => {
				const publication = submission.publications[submission.publications.length - 1];
				api(pageUrl('api/v1/submissions/' + made.submissionId + '/publications/' + publication.id + '/unpublish'), {method: 'PUT', headers: headers});
				api(pageUrl('api/v1/submissions/' + made.submissionId), {method: 'DELETE', headers: headers});
			});
		});
	});
});
