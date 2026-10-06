/** The create-task bottom sheet: type vocabulary, mirrored validation, POST. */

import { qs, make } from '../../core/dom.js';
import { ApiError } from '../../core/api-client.js';
import { navigate } from '../../core/router.js';
import { openSheet } from '../../components/bottom-sheet.js';
import { notify } from '../../components/notifications.js';
import { clearFieldErrors, showFieldErrors, focusFirstInvalid } from '../../components/form-errors.js';
import { taskTypes, createTask } from './tasks-api.js';

/** Opens the create sheet; onCreated reloads the day when the task lands. */
export function openCreateSheet(onCreated) {
  const sheet = openSheet('sheet-task-create');
  const form = qs('#task-create-form', sheet.element);
  const typeSelect = form.elements.task_type_id;
  const durationInput = form.elements.duration_minutes;
  const titleField = qs('[data-slot="title-field"]', sheet.element);
  const titleInput = qs('#task-create-title', sheet.element);
  const notesInput = form.elements.notes;

  let types = [];

  const selectedType = () => types.find((type) => type.id === Number(typeSelect.value)) ?? null;

  const syncTypeDependents = () => {
    const type = selectedType();
    if (type === null) {
      return;
    }
    durationInput.value = String(type.default_duration_minutes);
    const isGeneral = type.category === 'general';
    titleField.hidden = !isGeneral;
    if (!isGeneral) {
      titleInput.value = '';
    }
  };

  typeSelect.addEventListener('change', syncTypeDependents);

  taskTypes()
    .then((data) => {
      types = data.types;
      for (const type of types) {
        const option = make('option', {
          text: type.name_ar + ' · ' + type.default_duration_minutes + ' د',
          attrs: { value: String(type.id) },
        });
        typeSelect.appendChild(option);
      }
      syncTypeDependents();
    })
    .catch(() => {
      notify.error('تعذر تحميل أنواع المهام');
      sheet.close();
    });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearFieldErrors(form);

    const type = selectedType();
    const duration = Number(durationInput.value);
    const isGeneral = type !== null && type.category === 'general';
    const title = titleInput.value.trim();
    const notes = notesInput.value.trim();

    const localErrors = [];
    if (type === null) {
      localErrors.push({ field: 'task_type_id', message: 'اختر نوع المهمة' });
    }
    if (!Number.isInteger(duration) || duration < 1 || duration > 1440) {
      localErrors.push({ field: 'duration_minutes', message: 'المدة يجب أن تكون بين 1 و1440 دقيقة' });
    }
    if (isGeneral && title === '') {
      localErrors.push({ field: 'title', message: 'المهام العامة تحتاج عنواناً' });
    }
    if (localErrors.length > 0) {
      showFieldErrors(form, localErrors);
      focusFirstInvalid(form);
      return;
    }

    const submit = qs('button[type="submit"]', form);
    submit.disabled = true;
    try {
      const payload = {
        task_type_id: type.id,
        duration_minutes: duration,
      };
      if (title !== '') {
        payload.title = title;
      }
      if (notes !== '') {
        payload.notes = notes;
      }
      await createTask(payload);
      sheet.close();
      notify.success('أُضيفت المهمة إلى مهام اليوم');
      await onCreated();
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        sheet.close();
        navigate('/login');
        return;
      }
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
      submit.disabled = false;
    }
  });
}
