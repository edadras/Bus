import Alpine from 'alpinejs';
import { api, auth } from './lib/api.js';

Alpine.data('adminLogin', () => ({
    form: { mobile: '', password: '' },
    busy: false,
    error: null,

    async submit() {
        this.busy = true;
        this.error = null;

        try {
            const { data } = await api.post('/auth/login', {
                mobile: this.form.mobile,
                password: this.form.password,
                client: 'admin',
            });

            auth.token = data.token;
            window.location.href = '/admin';
        } catch (error) {
            // The server returns one uniform message for a wrong password and
            // an unknown account, so this page cannot be used to enumerate
            // which mobile numbers have staff accounts.
            this.error = error.message;
        } finally {
            this.busy = false;
        }
    },
}));
