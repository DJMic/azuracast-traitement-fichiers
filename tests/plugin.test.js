import { test } from "node:test";
import assert from "node:assert/strict";
import { merge } from "../bin/compose-override.mjs";
import { formatNumber, parseThreshold, plural, settingsPage } from "../frontend/tab.js";

const OTHER = `# Mon fichier
services:
  web:
    environment:
      AZURACAST_PLUGIN_MODE: "true"
      COMPOSER_PLUGIN_MODE: "true"
    volumes:
      - ./plugins/azuracast-animateurs-virtuels:/var/azuracast/www/plugins/azuracast-animateurs-virtuels:ro
      - ./plugins/azuracast-animateurs-virtuels/data:/var/azuracast/plugin-data/animateurs-virtuels:rw
`;

test("ajoute le montage sans toucher aux autres plugins", () => {
    const out = merge(OTHER);
    assert.match(out, /# Mon fichier/);
    assert.match(out, /azuracast-animateurs-virtuels:ro/);
    assert.match(out, /animateurs-virtuels:rw/);
    assert.match(out, /\.\/plugins\/azuracast-traitement-fichiers:\/var\/azuracast\/www\/plugins\/azuracast-traitement-fichiers:ro/);
});

test("idempotent", () => {
    const once = merge(OTHER);
    assert.equal(merge(once), once);
});

test("fichier absent : créé avec le mode plugin", () => {
    const out = merge("");
    assert.match(out, /AZURACAST_PLUGIN_MODE: "true"/);
    assert.match(out, /COMPOSER_PLUGIN_MODE: "true"/);
    assert.match(out, /azuracast-traitement-fichiers:ro/);
});

test("désinstallation : retire seulement ce plugin", () => {
    const out = merge(merge(OTHER), true);
    assert.doesNotMatch(out, /traitement-fichiers/);
    assert.match(out, /azuracast-animateurs-virtuels:ro/);
    assert.match(out, /AZURACAST_PLUGIN_MODE/);
});

test("page des paramètres de la station", () => {
    assert.equal(settingsPage("/station/3/settings", ""), "3");
    assert.equal(settingsPage("/station/3/settings/", ""), "3");
    assert.equal(settingsPage("/radio/station/12/settings", "/radio"), "12");
    assert.equal(settingsPage("/station/3/files", ""), null);
    assert.equal(settingsPage("/station/3/settings/x", ""), null);
    assert.equal(settingsPage("/admin/stations", ""), null);
});

test("nombres", () => {
    assert.equal(formatNumber(-14), "−14");
    assert.equal(formatNumber(4.25), "4,3");
    assert.equal(parseThreshold("-28,5"), -28.5);
    assert.equal(parseThreshold("−5"), -5);
    assert.equal(parseThreshold("abc"), null);
    assert.equal(plural(1, "fichier", "fichiers"), "1 fichier");
    assert.equal(plural(3, "fichier", "fichiers"), "3 fichiers");
});
