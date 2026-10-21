panel.plugin('graphek/social-media', {
	components: {
		'social-media-view': {
			props: {
				status: { type: Object, default: () => ({}) },
				configured: { type: Boolean, default: false },
				redirectUri: { type: String, default: '' },
				automaticAvailable: { type: Boolean, default: false },
				syncUrl: { type: String, default: '' },
			},
			data() {
				return {
					current: this.status,
					settings: this.settingsFrom(this.status),
					busy: false,
					operationError: '',
					message: '',
					syncKey: '',
					appId: this.status.app?.app_id ?? '',
					appSecret: '',
				};
			},
			watch: {
				status(value) {
					this.syncKey = '';
					this.appSecret = '';
					this.appId = value.app?.app_id ?? '';
					this.current = value;
					this.settings = this.settingsFrom(value);
				},
			},
			methods: {
				async saveApp(event) {
					event?.preventDefault();
					if (this.busy) return;
					const appId = this.appId.trim();
					const secret = this.appSecret;
					const retained = this.current.app?.secret_saved && appId === this.current.app?.app_id;
					if (!/^[1-9][0-9]{1,39}$/.test(appId) || (!secret && !retained) || (secret && !/^[\x21-\x7e]{16,512}$/.test(secret))) {
						this.operationError = 'Bitte eine numerische Instagram-App-ID und das zugehörige App-Geheimnis eingeben. Ein leeres Geheimnis behält den gespeicherten Wert nur bei derselben App-ID.';
						this.message = '';
						return;
					}
					this.appSecret = '';
					await this.run(async () => {
						this.current = await this.$api.post('social-media/instagram/app', { action: 'save', app_id: appId, app_secret: secret });
						this.appId = this.current.app.app_id;
					}, 'App-Konfiguration gespeichert. Du kannst jetzt das Instagram-Konto verbinden.', 'Die App-Konfiguration konnte nicht gespeichert werden. HTTPS, Administratorrechte und eine getrennte Kontoverbindung sind erforderlich.');
				},
				async clearApp() {
					if (this.busy || !window.confirm('Gespeicherte App-Zugangsdaten entfernen? Für eine neue Verbindung müssen sie erneut eingegeben werden.')) return;
					this.appSecret = '';
					await this.run(async () => {
						this.current = await this.$api.post('social-media/instagram/app', { action: 'clear' });
						this.appId = '';
					}, 'App-Zugangsdaten entfernt.', 'Die App-Zugangsdaten konnten nicht entfernt werden. Bitte zuerst die Kontoverbindung trennen.');
				},
				settingsFrom(status) {
					return {
						max_posts: status.settings?.max_posts ?? 20,
						interval_minutes: status.settings?.interval_minutes ?? 60,
						mode: status.settings?.mode ?? 'request',
					};
				},
				date(value) {
					if (!value) return 'Noch nicht';
					const numeric = typeof value === 'number' || /^\d+$/.test(String(value));
					const date = new Date(numeric ? Number(value) * 1000 : value);
					if (Number.isNaN(date.getTime())) return 'Unbekannt';
					return new Intl.DateTimeFormat('de-DE', {
						dateStyle: 'medium',
						timeStyle: 'short',
					}).format(date);
				},
				async refresh() {
					this.current = await this.$api.get('social-media/instagram/status');
				},
				async run(operation, successMessage, failureMessage) {
					if (this.busy) return;
					this.busy = true;
					this.operationError = '';
					this.message = '';
					try {
						await operation();
						this.message = successMessage;
					} catch {
						// Provider-/Transportfehler können vertrauliche URLs enthalten.
						// Nur die bereinigte Statusmeldung des Backends wird angezeigt.
						this.operationError = failureMessage;
					} finally {
						try {
							await this.refresh();
						} catch {
							this.operationError ||= 'Der aktuelle Verbindungsstatus konnte nicht geladen werden. Bitte die Seite neu laden.';
						}
						this.busy = false;
					}
				},
				async connect() {
					if (this.current.connected && !window.confirm('Instagram erneut verbinden? Wenn du ein anderes Konto auswählst, werden der bisherige lokale Feed und sein Sync-Schlüssel gelöscht.')) return;
					await this.run(async () => {
						const result = await this.$api.post('social-media/instagram/connect', { allow_replace: this.current.connected === true });
						const url = new URL(result.url);
						const central = this.current.connection_mode === 'central';
						const targetValid = central
							? url.origin === this.current.service_origin && /^\/connect\/[a-f0-9]{64}$/.test(url.pathname) && !url.search
							: ['instagram.com', 'www.instagram.com'].includes(url.hostname) && /^\/oauth\/authorize\/?$/.test(url.pathname);
						if (url.protocol !== 'https:' || !targetValid || url.username || url.password || url.port || url.hash) {
							throw new Error('Ungültige Verbindungsadresse');
						}
						window.location.assign(url.href);
					}, '', 'Die Instagram-Verbindung konnte nicht gestartet werden. Bitte die technische Einrichtung prüfen.');
				},
				async sync() {
					await this.run(
						() => this.$api.post('social-media/instagram/sync'),
						'Aktualisierung angefragt. Der Status zeigt das Ergebnis; bei Fehlern bleibt der letzte erfolgreiche Stand erhalten.',
						'Die Aktualisierung konnte nicht durchgeführt werden. Der letzte erfolgreiche Stand bleibt erhalten.',
					);
				},
				async disconnect() {
					if (!window.confirm('Instagram-Verbindung trennen und alle lokal gespeicherten Instagram-Beiträge und Vorschaubilder löschen? Redaktionelle Medien bleiben erhalten.')) return;
					await this.run(
						async () => {
							await this.$api.post('social-media/instagram/disconnect');
							this.syncKey = '';
						},
						'Die Instagram-Verbindung wurde getrennt.',
						'Die Verbindung konnte nicht getrennt werden. Bitte erneut versuchen.',
					);
				},
				async rotateSyncKey() {
					if (this.current.external_sync_enabled && !window.confirm('Neuen Sync-Schlüssel erstellen? Der bisherige Schlüssel wird sofort ungültig.')) return;
					this.syncKey = '';
					await this.run(async () => {
						const result = await this.$api.post('social-media/instagram/sync-key', { action: 'rotate' });
						this.syncKey = result.key;
					}, 'Schlüssel erstellt. Jetzt sicher kopieren: Er wird nur einmal angezeigt.', 'Der Sync-Schlüssel konnte nicht erstellt werden. HTTPS ist erforderlich.');
				},
				async revokeSyncKey() {
					if (!window.confirm('Externen Abruf sperren? Der bestehende Sync-Schlüssel wird sofort ungültig.')) return;
					await this.run(async () => {
						await this.$api.post('social-media/instagram/sync-key', { action: 'revoke' });
						this.syncKey = '';
					}, 'Externer Abruf gesperrt.', 'Der Sync-Schlüssel konnte nicht widerrufen werden.');
				},
				async save(event) {
					event?.preventDefault();
					const maxPosts = Number(this.settings.max_posts);
					const interval = Number(this.settings.interval_minutes);
					if (
						!Number.isInteger(maxPosts) || maxPosts < 1 || maxPosts > 100 ||
						!Number.isInteger(interval) || interval < 15 || interval > 1440 ||
						!['request', 'external'].includes(this.settings.mode)
					) {
						this.message = '';
						this.operationError = 'Bitte 1 bis 100 Beiträge und ein Intervall von 15 bis 1440 Minuten wählen.';
						return;
					}
					await this.run(
						async () => {
							await this.$api.post('social-media/instagram/settings', {
								max_posts: maxPosts,
								interval_minutes: interval,
								mode: this.settings.mode,
							});
						},
						'Einstellungen gespeichert.',
						'Die Einstellungen konnten nicht gespeichert werden.',
					);
				},
				button(h, text, action, extra = {}) {
					return h('k-button', {
						props: { type: 'button', variant: 'filled', disabled: this.busy, ...extra },
						on: { click: action },
					}, [text]);
				},
				notice(h, text, warning = false) {
					return h('p', {
						class: ['social-media-notice', { 'is-warning': warning }],
						attrs: { role: warning ? 'alert' : 'status' },
					}, [text]);
				},
				preview(h) {
					const posts = Array.isArray(this.current.preview) ? this.current.preview.slice(0, 6) : [];
					const types = { IMAGE: 'Bild', VIDEO: 'Video-Vorschau', CAROUSEL_ALBUM: 'Album-Vorschau' };
					return h('section', { class: 'social-media-card social-media-preview', attrs: { 'aria-labelledby': 'instagram-preview-title' } }, [
						h('h2', { attrs: { id: 'instagram-preview-title' } }, ['Letzte Beiträge']),
						h('p', { class: 'social-media-help' }, ['Bis zu sechs aktuelle Beiträge aus dem letzten erfolgreichen Abruf. Bilder werden ausschließlich von dieser Website geladen; Instagram öffnet sich erst beim Klick auf einen Beitragslink.']),
						posts.length ? h('ul', { class: 'social-media-posts' }, posts.map(post => h('li', { key: post.id, class: 'social-media-post' }, [
							h('img', { attrs: { src: post.image_url, alt: post.caption || 'Instagram-Vorschaubild', loading: 'lazy', decoding: 'async' } }),
							h('div', { class: 'social-media-post-body' }, [
								h('p', { class: 'social-media-post-meta' }, [types[post.type] || 'Beitrag', ' · ', h('time', { attrs: { datetime: post.timestamp } }, [this.date(post.timestamp)])]),
								h('p', { class: 'social-media-post-caption' }, [post.caption || 'Keine Bildunterschrift vorhanden.']),
								h('a', { attrs: { href: post.permalink, target: '_blank', rel: 'noopener noreferrer' } }, ['Auf Instagram ansehen (neuer Tab)']),
							]),
						]))) : h('p', { class: 'social-media-help' }, [
							!this.current.connected ? 'Verbinde zuerst ein Instagram-Konto und starte anschließend „Jetzt aktualisieren“.'
								: this.current.posts_count > 0 ? 'Keine darstellbaren lokalen Vorschaubilder vorhanden. Bitte „Jetzt aktualisieren“ starten.'
									: 'Noch keine Beiträge lokal gespeichert. Bitte „Jetzt aktualisieren“ starten. „Verbunden“ allein lädt noch keine Beiträge.',
						]),
					]);
				},
				usage(h) {
					const code = text => h('pre', [h('code', [text])]);
					return h('section', { class: 'social-media-card social-media-usage', attrs: { 'aria-labelledby': 'instagram-usage-title' } }, [
						h('h2', { attrs: { id: 'instagram-usage-title' } }, ['In Templates und Modulen verwenden']),
						h('p', ['Das Plugin stellt den gecachten Feed bereit, aber erzeugt keine Seiten oder fertige Social Wall. Ein Template oder Modul legt Anzahl, Layout und redaktionelles Fallback fest.']),
						h('h3', ['Mehrere Beiträge / Social Wall']),
						code('use Graphek\\SocialMedia\\Bridge;\n\n$posts = [];\nif (class_exists(Bridge::class) && Bridge::enabled(kirby())) {\n    try {\n        $posts = array_slice(Bridge::feed(kirby())->posts(), 0, 6);\n    } catch (\\Throwable) {\n        // Redaktionelles Fallback oder Modul ausblenden.\n    }\n}'),
						h('p', ['Jeder Eintrag enthält id, caption (unformatierter Text), timestamp (UTC), type (IMAGE, VIDEO, CAROUSEL_ALBUM), permalink, image_url (lokale Bild-URL) und local_image (relativer Plugin-Bildpfad). Neueste Beiträge stehen zuerst. Videos und Alben liefern ein einzelnes Vorschaubild, kein Video und keinen vollständigen Album-Slider.']),
						code('<img src="<?= esc($post[\'image_url\'], \'attr\') ?>"\n     alt="<?= esc($alt, \'attr\') ?>" loading="lazy">\n<p><?= esc($post[\'caption\']) ?></p>\n<a href="<?= esc($post[\'permalink\'], \'attr\') ?>"\n   target="_blank" rel="noopener noreferrer">Auf Instagram ansehen</a>'),
						h('p', ['Innerhalb einer foreach-Schleife über $posts verwenden; $alt als passende Bildbeschreibung definieren. Caption nie als HTML oder Rich Text ausgeben. Bei Links auf neue Tabs hinweisen. Für Kirby-Thumbnails: new \\Graphek\\SocialMedia\\LocalImage($post[\'local_image\'], $alt).']),
						h('h3', ['Nur das neueste Bild']),
						code('$image = null;\nif (class_exists(\\Graphek\\SocialMedia\\Bridge::class)) {\n    $image = \\Graphek\\SocialMedia\\Bridge::latestImage(\n        kirby(), \'Redaktionelle Bildbeschreibung\'\n    );\n}\n// $image ist ein Kirby-File-kompatibles LocalImage oder null.\n// Bei null das manuell gepflegte Bild verwenden.'),
						h('h3', ['Hinweise zur Integration']),
						h('ul', { class: 'social-media-usage-rules' }, [
							h('li', ['posts(), latest() und latestImage() lesen ausschließlich den lokalen Bestand. Niemals sync() während des Template-Renderings aufrufen.']),
							h('li', ['Nur image_url, LocalImage und lokale Kirby-Thumbnails verwenden. Keine Instagram-CDN-Bilder, Embed-Skripte, Iframes oder Browser-API-Aufrufe einbauen.']),
							h('li', ['Leere Feeds und deaktiviertes/fehlendes Plugin abfangen. Bei Abruffehlern bleibt der letzte erfolgreiche Stand erhalten; das ist nicht zwingend der aktuellste Instagram-Stand.']),
							h('li', ['Die Speicherung (maximal 100 Beiträge) wird hier verwaltet; die sichtbare Anzahl begrenzt das Modul separat. Aktualisierung erfolgt manuell, durch den eingestellten Seitenaufruf-Modus oder extern.']),
							h('li', ['Private state.json, Tokens und App-Geheimnisse niemals direkt lesen oder in Templates oder im Browser ausgeben. Die Management-API ist keine öffentliche Feed-API.']),
						]),
					]);
				},
			},
			render(h) {
				const connected = this.current.connected === true;
				const account = this.current.account;
				const reconnect = this.current.reconnect === true;
				const configured = this.current.configured ?? this.configured;
				const app = this.current.app ?? {};
				const central = this.current.connection_mode === 'central';
				const appDisabled = this.busy || connected || app.server_managed;
				const fact = (label, value) => h('div', { class: 'social-media-fact' }, [h('dt', label), h('dd', value)]);
				const numberField = (key, label, min, max, help) => h('k-number-field', {
					props: { id: `social-media-${key}`, name: key, label, min, max, step: 1, required: true, disabled: this.busy, help, value: this.settings[key] },
					on: { input: value => this.$set(this.settings, key, value) },
				});
				const connection = [
					h('k-headline', ['Instagram-Konto']),
					h('p', { class: 'social-media-connection', attrs: { 'data-connected': connected && !reconnect ? 'true' : 'false' } }, [
						connected ? `Verbunden${account?.username ? ` · @${account.username}` : ''}` : 'Nicht verbunden',
					]),
				];
				if (!configured) connection.push(this.notice(h, central ? 'Die Projektfreigabe fehlt. Bitte den Website-Betreiber kontaktieren.' : 'Die Instagram-App ist noch nicht eingerichtet. Hinterlege zuerst App-ID und App-Geheimnis unter „Meta-App einrichten“.', true));
				if (this.current.connection_notice === 'success') connection.push(this.notice(h, 'Instagram ist verbunden. Du kannst jetzt die Beiträge aktualisieren.'));
				if (this.current.connection_notice === 'failed') connection.push(this.notice(h, 'Die Anmeldung ist fehlgeschlagen oder abgelaufen. Bitte erneut verbinden.', true));
				if (reconnect) connection.push(this.notice(h, 'Die Kontoverbindung muss erneuert werden. Bis dahin bleibt der letzte erfolgreiche Beitragsstand sichtbar.', true));
				if (this.current.error) connection.push(this.notice(h, String(this.current.error), true));
				connection.push(h('dl', { class: 'social-media-facts' }, [
					fact('Gespeicherte Beiträge', String(this.current.posts_count ?? 0)),
					fact('Letzte erfolgreiche Aktualisierung', this.date(this.current.last_success)),
					fact('Letzter Versuch', this.date(this.current.last_attempt)),
					fact('Verbindung gültig bis', account?.expires_at ? this.date(account.expires_at) : 'Nicht verbunden'),
				]));
				if (this.current.next_attempt) connection.push(h('p', { class: 'social-media-help' }, [`Nächster Versuch frühestens: ${this.date(this.current.next_attempt)}`]));
				connection.push(h('div', { class: 'social-media-actions' }, [
					this.button(h, connected ? 'Neu verbinden' : 'Verbinden', this.connect, { icon: 'link', disabled: this.busy || !configured }),
					this.button(h, 'Jetzt aktualisieren', this.sync, { icon: 'refresh', disabled: this.busy || !connected || reconnect || !configured }),
					this.button(h, 'Verbindung trennen', this.disconnect, { icon: 'remove', disabled: this.busy || !connected }),
				]));
				if (!central && this.redirectUri) connection.push(h('div', { class: 'social-media-callback' }, [
					h('p', ['Diese Weiterleitungsadresse muss in der Meta-App freigegeben sein:']),
					h('code', [this.redirectUri]),
				]));
				const settings = h('form', {
					class: 'social-media-card social-media-settings',
					on: { submit: this.save },
				}, [
					h('k-headline', ['Speicherung und Aktualisierung']),
					numberField('max_posts', 'Maximal gespeicherte Beiträge', 1, 100, 'Höchstens 100. Beim Reduzieren werden nur die vom Plugin gespeicherten älteren Beiträge und Medien entfernt.'),
					numberField('interval_minutes', 'Aktualisierungsintervall in Minuten', 15, 1440, 'Das Intervall begrenzt Abrufe; ohne Seitenaufruf oder externen Trigger findet kein Abruf statt.'),
					h('k-select-field', {
						props: {
							id: 'social-media-mode', name: 'mode', label: 'Aktualisierung', required: true, disabled: this.busy, value: this.settings.mode,
							options: [
								{ value: 'request', text: 'Bei Seitenaufrufen' },
								{ value: 'external', text: 'Extern oder manuell' },
							],
						},
						on: { input: value => this.$set(this.settings, 'mode', value) },
					}),
					h('p', { class: 'social-media-help' }, [
						this.settings.mode === 'external'
							? 'Ein Cronjob kann den lokalen Synchronisierungsbefehl oder den optionalen Endpunkt mit Sync-Schlüssel nutzen. Kein zusätzlicher Kirby-Account nötig. Alternativ hier manuell aktualisieren.'
							: this.automaticAvailable
								? 'Fällige Aktualisierungen werden nach der Seitenausgabe gestartet. Besucher laden ausschließlich lokale Medien.'
								: 'Automatische Aktualisierung nach Seitenaufrufen ist auf diesem Server nicht verfügbar. Bitte einen externen Trigger einrichten oder manuell aktualisieren.',
					]),
					this.settings.mode === 'external' ? h('div', { class: 'social-media-callback' }, [
						h('h2', ['Optionaler externer Abruf']),
						h('p', [this.current.external_sync_enabled ? 'Ein Sync-Schlüssel ist aktiv.' : 'Externer Abruf ist gesperrt.']),
						h('p', ['Nur für einen externen Scheduler. Der Schlüssel erlaubt ausschließlich das Aktualisieren dieses Feeds. Instagram-Zugangsdaten werden hier nicht benötigt.']),
						h('code', [`POST ${this.syncUrl}`]),
						h('p', ['Schlüssel im HTTP-Header X-Instagram-Sync-Key über HTTPS senden, niemals in der URL.']),
						h('div', { class: 'social-media-actions' }, [
							this.button(h, this.current.external_sync_enabled ? 'Schlüssel erneuern' : 'Sync-Schlüssel erstellen', this.rotateSyncKey, { icon: 'key' }),
							this.current.external_sync_enabled ? this.button(h, 'Schlüssel widerrufen', this.revokeSyncKey, { icon: 'remove' }) : null,
						]),
						this.syncKey ? h('div', [
							h('p', ['Jetzt kopieren und sicher im Scheduler hinterlegen. Nach dem Verlassen der Ansicht wird der Schlüssel nicht erneut angezeigt.']),
							h('code', [this.syncKey]),
							this.button(h, 'Schlüssel ausblenden', () => { this.syncKey = ''; }),
						]) : null,
					]) : null,
					h('k-button', { props: { type: 'submit', icon: 'check', variant: 'filled', disabled: this.busy } }, ['Einstellungen speichern']),
				]);
				const appForm = !central && app.manageable ? h('form', {
					class: 'social-media-card social-media-app', on: { submit: this.saveApp },
				}, [
					h('k-headline', ['Meta-App einrichten']),
					h('p', { class: 'social-media-help' }, ['Einmalig die Instagram-App-ID und das Instagram-App-Geheimnis aus der Instagram-API-Einrichtung deiner Meta-App hinterlegen. Das sind keine Instagram-Login-Daten. Die Weiterleitungsadresse unten muss bei Meta freigegeben sein.']),
					app.server_managed ? this.notice(h, 'Die Zugangsdaten werden über die Serverkonfiguration verwaltet und können hier nicht geändert werden.') : null,
					connected && !app.server_managed ? this.notice(h, 'Vor dem Ändern der App-Zugangsdaten bitte zuerst die Kontoverbindung trennen.') : null,
					h('div', { class: 'social-media-app-fields' }, [
						h('div', { class: 'social-media-app-field' }, [
							h('label', { attrs: { for: 'instagram-app-id' } }, ['Instagram-App-ID']),
							h('input', { attrs: { id: 'instagram-app-id', type: 'text', inputmode: 'numeric', autocomplete: 'off', required: true, maxlength: 40, disabled: appDisabled }, domProps: { value: this.appId }, on: { input: event => { this.appId = event.target.value; } } }),
						]),
						h('div', { class: 'social-media-app-field' }, [
							h('label', { attrs: { for: 'instagram-app-secret' } }, ['Instagram-App-Geheimnis']),
							h('input', { attrs: { id: 'instagram-app-secret', type: 'password', autocomplete: 'new-password', maxlength: 512, disabled: appDisabled, placeholder: app.secret_saved ? 'Gespeichert – leer lassen zum Beibehalten' : 'App-Geheimnis eingeben', 'aria-describedby': 'instagram-app-secret-help' }, domProps: { value: this.appSecret }, on: { input: event => { this.appSecret = event.target.value; } } }),
							h('p', { class: 'social-media-help', attrs: { id: 'instagram-app-secret-help' } }, [app.secret_saved ? 'Ein Geheimnis ist gespeichert. Es wird nicht wieder angezeigt.' : 'Das Geheimnis wird ausschließlich im privaten Serverspeicher abgelegt, nicht im Seiteninhalt.']),
						]),
					]),
					!app.server_managed ? h('div', { class: 'social-media-actions' }, [
						h('k-button', { props: { type: 'submit', icon: 'check', variant: 'filled', disabled: appDisabled } }, ['App-Konfiguration speichern']),
						app.secret_saved ? this.button(h, 'App-Zugangsdaten entfernen', this.clearApp, { icon: 'remove', disabled: appDisabled }) : null,
					]) : null,
				]) : null;
				return h('k-panel-inside', { class: 'social-media-view' }, [
					h('k-header', ['Social Media']),
					h('div', { class: 'social-media-feedback', attrs: { 'aria-live': 'polite', 'aria-atomic': 'true' } }, [
						this.operationError ? this.notice(h, this.operationError, true) : null,
						this.message ? this.notice(h, this.message) : null,
						this.busy ? h('p', { class: 'social-media-help' }, ['Wird bearbeitet …']) : null,
					]),
					h('div', { class: 'social-media-grid', attrs: { 'aria-busy': String(this.busy) } }, [
						appForm,
						h('section', { class: 'social-media-card' }, connection),
						settings,
						this.preview(h),
						this.usage(h),
					]),
				]);
			},
		},
	},
});
