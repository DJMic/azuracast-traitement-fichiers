// Adds (or removes with --remove) the plugin mounts and plugin-mode variables
// in AzuraCast's docker-compose.override.yml, keeping all existing content, comments
// and the entries of other plugins.
// Usage: node bin/compose-override.mjs AZURACAST_DIR OUTPUT_FILE [--remove]

import { isMap, isScalar, isSeq, parseDocument } from "yaml";
import { lstatSync, readFileSync, writeFileSync } from "node:fs";
import { resolve } from "node:path";

export const NAME = "azuracast-traitement-fichiers";
export const MOUNTS = [
    // Plugin code, read-only. The plugin keeps its files in each station's config folder.
    { source: `./plugins/${NAME}`, target: `/var/azuracast/www/plugins/${NAME}`, mode: "ro" },
];
const FLAGS = ["AZURACAST_PLUGIN_MODE", "COMPOSER_PLUGIN_MODE"];

function mapping(document, parent, key) {
    let value = parent.get(key, true);
    if (value === undefined || (isScalar(value) && value.value === null)) {
        value = document.createNode({});
        parent.set(key, value);
    }
    if (!isMap(value) || value.has("<<")) {
        throw new Error(`« ${key} » doit être un dictionnaire YAML simple (sans alias). Modifiez le fichier à la main.`);
    }
    return value;
}

function volumeTarget(volume) {
    if (isScalar(volume) && typeof volume.value === "string") {
        const parts = volume.value.split(":");
        return (parts[1] ?? parts[0]).replace(/\/+$/, "");
    }
    if (isMap(volume)) {
        return String(volume.get("target") ?? "").replace(/\/+$/, "");
    }
    return null;
}

export function merge(text, remove = false) {
    const document = parseDocument(text || "", { uniqueKeys: true });
    if (document.errors.length) {
        throw new Error(document.errors.map((error) => error.message).join("\n"));
    }
    document.contents ??= document.createNode({});
    if (!isMap(document.contents)) {
        throw new Error("docker-compose.override.yml doit contenir un dictionnaire YAML.");
    }

    const services = mapping(document, document.contents, "services");
    const web = mapping(document, services, "web");

    let volumes = web.get("volumes", true);
    if (volumes === undefined || (isScalar(volumes) && volumes.value === null)) {
        volumes = document.createNode([]);
        web.set("volumes", volumes);
    }
    if (!isSeq(volumes)) {
        throw new Error("services.web.volumes doit être une liste YAML.");
    }

    for (const mount of MOUNTS) {
        const existing = volumes.items.findIndex((volume) => volumeTarget(volume) === mount.target);
        if (remove) {
            if (existing >= 0) volumes.items.splice(existing, 1);
            continue;
        }
        const node = document.createNode(`${mount.source}:${mount.target}:${mount.mode}`);
        if (existing >= 0) volumes.items[existing] = node;
        else volumes.add(node);
    }

    if (remove) {
        if (volumes.items.length === 0) web.delete("volumes");
        // The plugin-mode flags are left in place: other plugins may need them.
        return document.toString();
    }

    const environment = web.get("environment", true);
    if (isSeq(environment)) {
        for (const flag of FLAGS) {
            const item = environment.items.find((entry) => isScalar(entry) && String(entry.value).split("=")[0] === flag);
            if (item) item.value = `${flag}=true`;
            else environment.add(document.createNode(`${flag}=true`));
        }
    } else {
        const variables = mapping(document, web, "environment");
        for (const flag of FLAGS) variables.set(flag, "true");
    }

    return document.toString();
}

const [root, output, option] = process.argv.slice(2);
if (root && output) {
    const file = resolve(root, "docker-compose.override.yml");
    let text = "";
    let mode = 0o644;
    try {
        const stat = lstatSync(file);
        if (!stat.isFile()) throw new Error("docker-compose.override.yml doit être un fichier ordinaire.");
        text = readFileSync(file, "utf8");
        mode = stat.mode & 0o777;
    } catch (error) {
        if (error.code !== "ENOENT") throw error;
    }
    writeFileSync(output, merge(text, option === "--remove"), { mode });
}
