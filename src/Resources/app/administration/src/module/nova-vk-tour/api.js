/**
 * Kleine Hilfen für die Admin-API der Touren (/api/_action/nova-versandkosten/tour/…).
 */
const http = () => Shopware.Application.getContainer('init').httpClient;

const headers = () => ({
    Accept: 'application/json',
    Authorization: `Bearer ${Shopware.Service('loginService').getToken()}`,
});

export function apiGet(path, params = {}) {
    return http().get(`_action/nova-versandkosten/tour/${path}`, { headers: headers(), params }).then((r) => r.data);
}

export function apiPost(path, data = {}) {
    return http().post(`_action/nova-versandkosten/tour/${path}`, data, { headers: headers() }).then((r) => r.data);
}

export function apiDelete(path) {
    return http().delete(`_action/nova-versandkosten/tour/${path}`, { headers: headers() });
}

export function apiDownload(path, params, fileName) {
    return http().get(`_action/nova-versandkosten/tour/${path}`, { headers: headers(), params, responseType: 'blob' }).then((r) => {
        const url = window.URL.createObjectURL(r.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = fileName;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    });
}

export function errorText(error, fallback) {
    const errors = error?.response?.data?.errors;
    if (Array.isArray(errors) && errors.length) {
        return errors.map((e) => (typeof e === 'string' ? e : (e.detail || e.title || ''))).join(' ');
    }
    return fallback;
}

/** „Sa, 14.11.2026“ */
export function formatDate(value) {
    if (!value) {
        return '–';
    }
    const date = new Date(`${String(value).substring(0, 10)}T12:00:00`);
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }
    return date.toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' });
}

export function formatDateTime(value) {
    if (!value) {
        return '–';
    }
    const date = new Date(String(value).replace(' ', 'T') + (String(value).includes('T') ? '' : 'Z'));
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('de-DE');
}
