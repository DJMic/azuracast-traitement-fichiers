// AzuraCast Traitement Fichiers – "Traitement Fichiers" tab.
// Served as-is (no build) and injected into AzuraCast's panel pages by the plugin.
// On the station settings page (/station/<id>/settings), it adds a tab between "AutoDJ" and
// "HLS". The tab has its own "Enregistrer" button: AzuraCast's "Save Changes" button only saves
// AzuraCast's own fields, so it is hidden while this tab is shown.

const SUFFIX = "/traitement-fichiers/assets/tab.js";
const ITEM_ID = "traitement-fichiers-nav-item";
const BUTTON_ID = "traitement-fichiers-tab";
const PANE_ID = "traitement-fichiers-content";
const STYLE_ID = "traitement-fichiers-style";
const ACTIVE = "traitement-fichiers-active";
const LABEL = "Traitement Fichiers";
const POLL_MS = 30000;

const CSS = `
section.nav-content.${ACTIVE} > :not(#${PANE_ID}) { display: none !important; }
form.${ACTIVE} > .buttons { display: none !important; }
#${PANE_ID} .tf-playlists { max-height: 16rem; overflow-y: auto; }
#${PANE_ID} .tf-facts li { margin-bottom: .25rem; }
`;

/** Small DOM helper: h("div", {class: "x"}, "text", child…). Text is always set as text. */
function h(document, tag, attributes = {}, ...children) {
    const element = document.createElement(tag);
    for (const [name, value] of Object.entries(attributes)) {
        if (value === false || value === null || value === undefined) continue;
        if (name.startsWith("on")) element.addEventListener(name.slice(2), value);
        else if (name === "class") element.className = value;
        else element.setAttribute(name, value === true ? "" : String(value));
    }
    for (const child of children.flat()) {
        if (child === null || child === undefined || child === false) continue;
        element.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }
    return element;
}

export function formatNumber(value) {
    return String(Math.round(Number(value) * 10) / 10).replace(".", ",").replace("-", "−");
}

export function plural(count, one, many) {
    return `${count} ${count > 1 ? many : one}`;
}

export function parseThreshold(text) {
    const value = Number(String(text).trim().replace(",", ".").replace("−", "-"));
    return Number.isFinite(value) ? value : null;
}

/** Station whose settings page is shown, or null. */
export function settingsPage(pathname, basePath) {
    const prefix = `${basePath}/station/`;
    if (!pathname.startsWith(prefix)) return null;
    const match = pathname.slice(prefix.length).match(/^([1-9]\d*)\/settings\/?$/);
    return match ? match[1] : null;
}

function mount(document, window, basePath) {
    let frame = null;
    let current = null; // {stationId, form, nav, content, item, button, pane, previous, state, timer}

    function api(stationId, path, body = null) {
        const options = {credentials: "same-origin", headers: {Accept: "application/json"}};
        if (body !== null) {
            options.method = "POST";
            options.headers["Content-Type"] = "application/json";
            options.headers["X-CSRF-Token"] = current?.state?.csrf ?? "";
            options.body = JSON.stringify(body);
        }
        return window.fetch(`${basePath}/traitement-fichiers/${stationId}${path}`, options).then(async (response) => {
            let data = null;
            try {
                data = await response.json();
            } catch {
                data = null;
            }
            if (!response.ok || data === null) {
                throw new Error(data?.message || `Erreur ${response.status} du serveur.`);
            }
            return data;
        });
    }

    function findTabs() {
        const form = document.querySelector('section[aria-labelledby="hdr_edit_profile"] form.vue-form');
        const nav = form?.querySelector(':scope > nav[role="tablist"]');
        const content = form?.querySelector(":scope > section.nav-content");
        if (!form || !nav || !content) return null;

        const items = [...nav.querySelectorAll(":scope > .nav-item")].filter((item) => item.id !== ITEM_ID);
        const text = (item) => item.querySelector("button")?.textContent.trim().toLowerCase() ?? "";
        const id = (item) => item.querySelector("button")?.id ?? "";
        const autodj = items.find((item) => id(item) === "autodj-tab" || text(item) === "autodj");
        const hls = items.find((item) => id(item) === "hls-tab" || text(item) === "hls");
        if (!autodj && !hls) return null; // no broadcasting permission: no tab
        return {form, nav, content, after: autodj ?? null, before: autodj ? null : hls};
    }

    function refresh() {
        frame = null;
        const stationId = settingsPage(window.location.pathname, basePath);
        const tabs = stationId ? findTabs() : null;

        if (!tabs) {
            // Left the page, or AzuraCast rebuilt its form: remove ours (it is added again with the form).
            if (current && (!stationId || !document.contains(current.form))) teardown();
            return;
        }
        if (current && (current.form !== tabs.form || current.stationId !== stationId)) teardown();
        if (!current) setup(stationId, tabs);

        // Put the entry (back) in place if AzuraCast re-rendered its tabs.
        const {item, pane} = current;
        if (tabs.after && item.previousElementSibling !== tabs.after) tabs.after.after(item);
        else if (tabs.before && item.nextElementSibling !== tabs.before) tabs.before.before(item);
        if (pane.parentElement !== tabs.content) tabs.content.append(pane);
    }

    function setup(stationId, tabs) {
        if (!document.getElementById(STYLE_ID)) {
            document.head.append(h(document, "style", {id: STYLE_ID}, CSS));
        }
        const button = h(document, "button", {
            id: BUTTON_ID,
            class: "nav-link",
            type: "button",
            role: "tab",
            "aria-controls": PANE_ID,
            "aria-selected": "false",
            onclick: (event) => {
                event.preventDefault();
                activate();
            },
        }, LABEL);
        const item = h(document, "div", {id: ITEM_ID, class: "nav-item"}, button);
        const pane = h(document, "div", {id: PANE_ID, role: "tabpanel", "aria-labelledby": BUTTON_ID, hidden: true});
        // Enter in a field must not submit AzuraCast's own form.
        pane.addEventListener("keydown", (event) => {
            if (event.key === "Enter" && event.target.matches("input")) {
                event.preventDefault();
                save();
            }
        });

        // Any other tab: leave ours first, then let AzuraCast handle the click.
        const onNavClick = (event) => {
            const other = event.target.closest?.("button.nav-link");
            if (other && other !== button && current?.active) deactivate();
        };
        tabs.nav.addEventListener("click", onNavClick, true);

        current = {stationId, form: tabs.form, nav: tabs.nav, content: tabs.content, item, button, pane,
            onNavClick, active: false, previous: null, state: null, timer: null, loading: null};
        if (tabs.after) tabs.after.after(item);
        else tabs.before.before(item);
        tabs.content.append(pane);
    }

    function teardown() {
        if (!current) return;
        window.clearInterval(current.timer);
        current.nav.removeEventListener("click", current.onNavClick, true);
        current.item.remove();
        current.pane.remove();
        current.form.classList.remove(ACTIVE);
        current.content.classList.remove(ACTIVE);
        current = null;
    }

    function activate() {
        if (!current || current.active) return;
        const previous = current.nav.querySelector(`:scope > .nav-item > button.nav-link.active:not(#${BUTTON_ID})`);
        current.previous = previous;
        previous?.classList.remove("active");
        previous?.setAttribute("aria-selected", "false");
        current.button.classList.add("active");
        current.button.setAttribute("aria-selected", "true");
        current.form.classList.add(ACTIVE);
        current.content.classList.add(ACTIVE);
        current.pane.hidden = false;
        current.active = true;
        load();
        current.timer = window.setInterval(() => {
            if (current?.active && current.state?.backfill && !document.hidden) load(true);
        }, POLL_MS);
    }

    function deactivate() {
        if (!current?.active) return;
        current.button.classList.remove("active");
        current.button.setAttribute("aria-selected", "false");
        // AzuraCast still considers its previous tab active: give it its look back.
        if (current.previous && current.nav.contains(current.previous)) {
            current.previous.classList.add("active");
            current.previous.setAttribute("aria-selected", "true");
        }
        current.form.classList.remove(ACTIVE);
        current.content.classList.remove(ACTIVE);
        current.pane.hidden = true;
        current.active = false;
        window.clearInterval(current.timer);
        current.timer = null;
    }

    function load(quiet = false) {
        const view = current;
        if (!view || view.loading) return;
        if (!quiet && !view.state) {
            view.pane.replaceChildren(h(document, "p", {class: "text-muted"}, "Chargement…"));
        }
        view.loading = api(view.stationId, "/state")
            .then((state) => {
                if (current !== view) return;
                if (quiet && view.state) {
                    view.state = state;
                    renderStatus();
                } else {
                    view.state = state;
                    render();
                }
            })
            .catch((error) => {
                if (current !== view) return;
                if (!view.state) {
                    view.pane.replaceChildren(h(document, "div", {class: "alert alert-danger"},
                        `Impossible de charger « ${LABEL} » : ${error.message}`));
                }
            })
            .finally(() => {
                view.loading = null;
            });
    }

    function message(kind, text) {
        const box = current?.pane.querySelector(".tf-message");
        if (!box) return;
        box.replaceChildren(text ? h(document, "div", {class: `alert alert-${kind} mb-0`, role: "status"}, text) : "");
    }

    function render() {
        const {pane, state} = current;
        const s = state.settings;
        const field = (key, label, help) => {
            const [min, max] = state.limits[key];
            return h(document, "div", {class: "col-md-4"},
                h(document, "label", {class: "form-label", for: `tf-${key}`}, label),
                h(document, "div", {class: "input-group"},
                    h(document, "input", {
                        id: `tf-${key}`, class: "form-control", type: "number", step: "0.5",
                        min, max, value: s[key], inputmode: "decimal", required: true,
                    }),
                    h(document, "span", {class: "input-group-text"}, "dB")),
                h(document, "div", {class: "form-text"}, help));
        };

        const playlistList = state.playlists.length === 0
            ? h(document, "p", {class: "text-muted mb-0"}, "Cette station n'a aucune playlist.")
            : h(document, "div", {class: "tf-playlists border rounded p-2"},
                state.playlists.map((playlist) => h(document, "div", {class: "form-check"},
                    h(document, "input", {
                        class: "form-check-input tf-excluded", type: "checkbox", id: `tf-playlist-${playlist.id}`,
                        value: playlist.id, checked: s.excluded_playlists.includes(playlist.id),
                    }),
                    h(document, "label", {class: "form-check-label", for: `tf-playlist-${playlist.id}`}, playlist.name))));

        const fade = state.crossfade_enabled
            ? `${formatNumber(state.max_fade_out)} s, la durée du fondu enchaîné réglée dans l'onglet AutoDJ`
                + (state.autocue_enabled ? " (ce champ n'y est visible que lorsque AutoCue est désactivé)" : "")
            : `${formatNumber(state.max_fade_out)} s (le fondu enchaîné de l'onglet AutoDJ est désactivé)`;

        pane.replaceChildren(
            state.owner ? h(document, "div", {class: "alert alert-warning"},
                `Les médias de cette station sont partagés avec la station « ${state.owner.name} ». `
                + "Ce sont les réglages de cette station-là qui s'appliquent aux fichiers.") : "",
            h(document, "p", {class: "text-muted"},
                "Calcule une seule fois les points de cue et le gain des nouveaux fichiers, une fois qu'AzuraCast "
                + "a fini de les traiter, et les enregistre dans leurs points de cue personnalisés. Un fichier qui a "
                + "déjà une de ces valeurs (dans ses tags ou saisie à la main) n'est jamais modifié."),
            h(document, "div", {class: "form-check form-switch mb-4"},
                h(document, "input", {class: "form-check-input", type: "checkbox", role: "switch", id: "tf-enabled", checked: s.enabled}),
                h(document, "label", {class: "form-check-label", for: "tf-enabled"}, "Activer le traitement des fichiers"),
                h(document, "div", {class: "form-text"},
                    "Seuls les fichiers ajoutés après la première activation sont analysés automatiquement. "
                    + "Pour les autres, utilisez « Fichiers existants » plus bas.")),
            h(document, "fieldset", {class: "mb-4"},
                h(document, "legend", {class: "h5"}, "Seuils"),
                h(document, "p", {class: "form-text mt-0"},
                    "Comme dans l'AutoCue de Liquidsoap, les seuils sont relatifs à la sonie de chaque morceau : "
                    + "pour un titre à −9 LUFS, un seuil de −28 dB correspond à −37 dB."),
                h(document, "div", {class: "row g-3"},
                    field("cue_in_threshold", "Seuil de début (cue_in)",
                        "Le début est placé juste avant la première mesure au-dessus de ce seuil. Par défaut : −28 dB."),
                    field("cue_out_threshold", "Seuil de fin (cue_out)",
                        "La fin est placée après la dernière mesure au-dessus de ce seuil. Par défaut : −28 dB."),
                    field("cross_threshold", "Seuil de fondu enchaîné (cross)",
                        "Le titre suivant démarre (cross_start_next) après la dernière mesure au-dessus de ce seuil. "
                        + "Par défaut : −5 dB."))),
            h(document, "fieldset", {class: "mb-4"},
                h(document, "legend", {class: "h5"}, "Playlists exclues"),
                h(document, "p", {class: "form-text mt-0"},
                    "Les fichiers de ces playlists ne sont jamais analysés (par exemple les jingles)."),
                playlistList),
            h(document, "ul", {class: "tf-facts small text-muted mb-4"},
                h(document, "li", {}, `Gain (amplify) calculé pour une sonie de ${formatNumber(state.target_lufs)} LUFS, arrondi à 0,1 dB.`),
                h(document, "li", {}, `Fondu de sortie limité à ${fade}.`),
                h(document, "li", {}, `Les fichiers de plus de ${formatNumber(state.max_duration / 60)} minutes ne sont pas analysés.`),
                h(document, "li", {}, "Début précis, sans fondu d'entrée (fade_in = 0). Pas de détection des fins tenues.")),
            h(document, "div", {class: "buttons mb-3"},
                h(document, "button", {class: "btn btn-lg btn-primary tf-save", type: "button", onclick: save}, "Enregistrer")),
            h(document, "div", {class: "tf-message mb-3"}),
            h(document, "hr"),
            h(document, "h3", {class: "h5"}, "Fichiers existants"),
            h(document, "div", {class: "tf-status"}),
        );

        renderStatus();
    }

    function renderStatus() {
        const {pane, state} = current;
        const box = pane.querySelector(".tf-status");
        if (!box) return;
        const lines = [];
        const before = Math.max(0, state.existing - state.pending);

        if (state.backfill) {
            lines.push(h(document, "p", {},
                `Analyse en cours, en arrière-plan : ${plural(state.existing, "fichier restant", "fichiers restants")}. `
                + "Elle avance chaque minute ; vous pouvez quitter cette page."),
            h(document, "button", {class: "btn btn-outline-secondary", type: "button", onclick: () => backfill(false)}, "Arrêter"));
        } else if (before > 0) {
            lines.push(h(document, "p", {},
                `${plural(before, "fichier déjà présent", "fichiers déjà présents")} dans les médias `
                + `${before > 1 ? "n'ont" : "n'a"} aucun point de cue (fichiers de plus de 8 minutes et playlists exclues non comptés).`),
            h(document, "button", {class: "btn btn-outline-primary", type: "button", disabled: !state.settings.enabled,
                onclick: () => backfill(true)}, before > 1 ? "Analyser ces fichiers" : "Analyser ce fichier"));
            if (!state.settings.enabled) {
                lines.push(h(document, "div", {class: "form-text"}, "Activez d'abord le traitement des fichiers."));
            }
        } else {
            lines.push(h(document, "p", {class: "text-muted"}, "Tous les fichiers concernés ont leurs points de cue."));
        }
        if (state.pending > 0 && !state.backfill) {
            lines.push(h(document, "p", {class: "mt-3 mb-0"},
                `${plural(state.pending, "nouveau fichier", "nouveaux fichiers")} en attente d'analyse (une minute après le traitement d'AzuraCast).`));
        }
        if (state.failed > 0) {
            lines.push(h(document, "p", {class: "mt-3 mb-0 text-danger"},
                `${plural(state.failed, "fichier n'a", "fichiers n'ont")} pas pu être analysé${state.failed > 1 ? "s" : ""} `
                + "(détails dans le journal d'AzuraCast, Administration › Journaux du système). "
                + "Un nouvel essai a lieu après 24 h, trois fois au plus."));
        }
        box.replaceChildren(...lines);
    }

    function setBusy(busy) {
        current?.pane.querySelectorAll("button").forEach((button) => {
            if (busy) {
                button.dataset.wasDisabled = button.disabled ? "1" : "";
                button.disabled = true;
            } else {
                button.disabled = button.dataset.wasDisabled === "1";
            }
        });
    }

    function save() {
        const view = current;
        if (!view?.state) return;
        const pane = view.pane;
        const values = {};
        for (const key of ["cue_in_threshold", "cue_out_threshold", "cross_threshold"]) {
            const value = parseThreshold(pane.querySelector(`#tf-${key}`).value);
            if (value === null) {
                message("danger", "Saisissez un nombre pour chaque seuil.");
                pane.querySelector(`#tf-${key}`).focus();
                return;
            }
            values[key] = value;
        }
        const body = {
            enabled: pane.querySelector("#tf-enabled").checked,
            ...values,
            excluded_playlists: [...pane.querySelectorAll(".tf-excluded:checked")].map((input) => Number(input.value)),
        };
        setBusy(true);
        message("", "");
        api(view.stationId, "/settings", body)
            .then((state) => {
                if (current !== view) return;
                view.state = {...view.state, ...state};
                render();
                message("success", state.message);
            })
            .catch((error) => {
                if (current !== view) return;
                setBusy(false);
                message("danger", error.message);
            });
    }

    function backfill(start) {
        const view = current;
        if (!view?.state) return;
        setBusy(true);
        api(view.stationId, "/backfill", {start})
            .then((state) => {
                if (current !== view) return;
                view.state = {...view.state, ...state};
                setBusy(false);
                renderStatus();
                message("success", state.message);
            })
            .catch((error) => {
                if (current !== view) return;
                setBusy(false);
                message("danger", error.message);
            });
    }

    function schedule() {
        if (frame === null) frame = window.requestAnimationFrame(refresh);
    }

    new window.MutationObserver(schedule).observe(document.body, {childList: true, subtree: true});
    window.addEventListener("popstate", schedule);
    document.addEventListener("vue-ready", schedule);
    schedule();
}

if (typeof window !== "undefined" && typeof document !== "undefined" && !window.traitementFichiersTab) {
    const url = new URL(import.meta.url);
    if (url.pathname.endsWith(SUFFIX)) {
        window.traitementFichiersTab = true;
        mount(document, window, url.pathname.slice(0, -SUFFIX.length));
    }
}
