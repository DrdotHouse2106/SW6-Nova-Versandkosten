/**
 * Rechte des Versandkosten-Moduls – unverändert gegenüber 1.2.x:
 * - Einstellungen, Tarife, Energiezuschläge, Preisprüfung: „Einstellungen“ (system.system_config)
 * - Touren: Rolle „Bestellungen → Touren“ (nova_vk_tour.viewer)
 *
 * Der Menüeintrag erscheint, sobald eines der beiden Leserechte vorhanden ist. Shopware prüft
 * `privilege` an Menüeinträgen mit genau einem Schlüssel; deshalb liefert eine Abfrage zur Laufzeit
 * (nach dem Login sind die Rechte bekannt) den Schlüssel, den der Benutzer tatsächlich hat.
 */
export const SETTINGS_PRIVILEGE = 'system.system_config';
export const TOUR_PRIVILEGE = 'nova_vk_tour.viewer';

export function modulePrivilege() {
    let acl = null;
    try {
        acl = Shopware.Service('acl');
    } catch {
        acl = null;
    }
    if (acl && !acl.can(SETTINGS_PRIVILEGE) && acl.can(TOUR_PRIVILEGE)) {
        return TOUR_PRIVILEGE;
    }

    return SETTINGS_PRIVILEGE;
}
