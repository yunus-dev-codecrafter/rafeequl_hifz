/** Login view: form → POST /auth/login → hand off to the dashboard. */

import { renderInto, qs } from '../core/dom.js';
import { ApiError } from '../core/api-client.js';
import { login } from '../core/auth.js';
import { navigate } from '../core/router.js';
import { clearFieldErrors, showFieldErrors, focusFirstInvalid } from '../components/form-errors.js';
import { notify } from '../components/notifications.js';

export function renderLogin(container) {
  renderInto(container, 'view-login');
  const form = qs('#login-form', container);
  const submit = qs('#login-submit', container);

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearFieldErrors(form);
    submit.disabled = true;

    try {
      await login({
        email: form.email.value.trim(),
        password: form.password.value,
      });
      notify.success('أهلاً بك مجدداً');
      navigate('/');
    } catch (error) {
      if (error instanceof ApiError && error.errors.length > 0) {
        const shown = showFieldErrors(form, error.errors);
        if (shown) {
          focusFirstInvalid(form);
        } else {
          notify.error(error.message);
          form.password.focus();
        }
      } else {
        notify.error('تعذر الاتصال بالخادم');
        form.password.focus();
      }
    } finally {
      submit.disabled = false;
    }
  });
}
