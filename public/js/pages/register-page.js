/** Registration view: client-side confirmation, then POST /auth/register. */

import { renderInto, qs } from '../core/dom.js';
import { ApiError } from '../core/api-client.js';
import { register } from '../core/auth.js';
import { navigate } from '../core/router.js';
import { clearFieldErrors, showFieldErrors, focusFirstInvalid } from '../components/form-errors.js';
import { notify } from '../components/notifications.js';

const MISMATCH_MESSAGE = 'كلمتا المرور غير متطابقتين';

export function renderRegister(container) {
  renderInto(container, 'view-register');
  const form = qs('#register-form', container);
  const submit = qs('#register-submit', container);

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearFieldErrors(form);

    if (form.password.value !== form.password_confirm.value) {
      showFieldErrors(form, [{ field: 'password_confirm', message: MISMATCH_MESSAGE }]);
      focusFirstInvalid(form);
      return;
    }

    submit.disabled = true;
    try {
      const payload = {
        email: form.email.value.trim(),
        password: form.password.value,
      };
      const displayName = form.display_name.value.trim();
      if (displayName) {
        payload.display_name = displayName;
      }

      await register(payload);
      notify.success('تم إنشاء الحساب بنجاح');
      navigate('/');
    } catch (error) {
      if (error instanceof ApiError && error.errors.length > 0) {
        const shown = showFieldErrors(form, error.errors);
        if (shown) {
          focusFirstInvalid(form);
        } else {
          notify.error(error.message);
        }
      } else {
        notify.error('تعذر الاتصال بالخادم');
      }
    } finally {
      submit.disabled = false;
    }
  });
}
