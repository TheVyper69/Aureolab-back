import { usersService } from '../services/usersService.js';

function safe(v) {
  return String(v ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;');
}

function roleBadge(role) {
  if (role === 'optica') return 'text-bg-info';
  if (role === 'employee') return 'text-bg-secondary';
  if (role === 'admin') return 'text-bg-primary';
  return 'text-bg-dark';
}

function activeBadge(active) {
  return active
    ? '<span class="badge text-bg-success">Activo</span>'
    : '<span class="badge text-bg-danger">Inactivo</span>';
}

function initDt(selector) {
  if (window.$ && $.fn.dataTable) {
    if ($.fn.DataTable.isDataTable(selector)) {
      $(selector).DataTable().destroy();
    }

    $(selector).DataTable({
      pageLength: 10,
      language: {
        search: "Buscar:",
        lengthMenu: "Mostrar _MENU_",
        info: "Mostrando _START_ a _END_ de _TOTAL_",
        paginate: { previous: "Anterior", next: "Siguiente" },
        zeroRecords: "No hay registros"
      }
    });
  }
}

function extractError(err) {
  const data = err?.response?.data;

  if (data?.errors) {
    return Object.values(data.errors).flat().join('<br>');
  }

  return data?.message || err?.message || 'Ocurrió un error';
}

function eyeIcon(open = false) {
  if (open) {
    return `
      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
        <path d="M13.359 11.238 15 12.879l-.707.707-1.744-1.744A8.717 8.717 0 0 1 8 13c-5 0-7.777-4.5-7.777-4.5a15.77 15.77 0 0 1 2.873-3.19L1 3.414l.707-.707 14 14-.707.707-1.641-1.641zM11.297 9.176l-1.468-1.468a2 2 0 0 1-2.122-2.122L6.382 4.261A3 3 0 0 0 11.297 9.176z"/>
        <path d="M11.354 7.233a3 3 0 0 0-3.587-3.587l1.839 1.839a2 2 0 0 1 1.748 1.748z"/>
        <path d="M8 3c5 0 7.777 4.5 7.777 4.5a15.75 15.75 0 0 1-2.223 2.592l-.72-.72A14.76 14.76 0 0 0 14.576 7.5S12.06 4 8 4c-.958 0-1.84.195-2.64.5l-.775-.775A8.725 8.725 0 0 1 8 3z"/>
      </svg>
    `;
  }

  return `
    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
      <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.12 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/>
      <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>
    </svg>
  `;
}

function getCheckedPaymentMethods() {
  return Array.from(document.querySelectorAll('.js-optica-payment:checked'))
    .map(el => Number(el.value))
    .filter(v => [1, 2, 3].includes(v));
}

function setCheckedPaymentMethods(values = []) {
  const set = new Set((Array.isArray(values) ? values : []).map(v => Number(v)));
  document.querySelectorAll('.js-optica-payment').forEach(el => {
    el.checked = set.has(Number(el.value));
  });
}

export async function renderUsers(outlet) {
  let users = [];
  let editingUserId = null;

  let modal = null;
  let outletClickHandler = null;
  let saveHandler = null;
  let newHandler = null;

  async function loadData() {
    users = await usersService.list();
    users = Array.isArray(users) ? users : [];
  }

  function cleanupListeners() {
    if (outletClickHandler) {
      outlet.removeEventListener('click', outletClickHandler);
      outletClickHandler = null;
    }

    const btnSave = document.getElementById('btnSaveUser');
    if (btnSave && saveHandler) {
      btnSave.removeEventListener('click', saveHandler);
      saveHandler = null;
    }

    const btnNew = outlet.querySelector('#btnNewUser');
    if (btnNew && newHandler) {
      btnNew.removeEventListener('click', newHandler);
      newHandler = null;
    }
  }

  function renderTables() {
    cleanupListeners();

    const employees = users.filter(u => u.role === 'employee' || u.role === 'admin');
    const opticas = users.filter(u => u.role === 'optica');

    outlet.innerHTML = `
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h4 class="mb-0">Usuarios</h4>
        <button class="btn btn-brand" id="btnNewUser">Dar de alta</button>
      </div>

      <div class="card p-3 mb-4">
        <h5 class="mb-3">Usuarios óptica</h5>
        <div class="table-responsive">
          <table id="tblOpticasUsers" class="table table-striped align-middle" style="width:100%">
            <thead>
              <tr>
                <th>Nombre</th>
                <th>Email</th>
                <th>Teléfono</th>
                <th>Óptica</th>
                <th>Rol</th>
                <th>Estatus</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              ${opticas.map(u => `
                <tr>
                  <td>${safe(u.name)}</td>
                  <td>${safe(u.email)}</td>
                  <td>${safe(u.phone || '—')}</td>
                  <td>${safe(u.optica_nombre || '—')}</td>
                  <td><span class="badge ${roleBadge(u.role)}">${safe(u.role)}</span></td>
                  <td>${activeBadge(u.active)}</td>
                  <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-brand me-1" data-edit="${u.id}">Editar</button>
                    <button class="btn btn-sm btn-outline-danger" data-del="${u.id}">Eliminar</button>
                  </td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      </div>

      <div class="card p-3 mb-4">
        <h5 class="mb-3">Usuarios empleados y admin</h5>
        <div class="table-responsive">
          <table id="tblEmployeesUsers" class="table table-striped align-middle" style="width:100%">
            <thead>
              <tr>
                <th>Nombre</th>
                <th>Email</th>
                <th>Teléfono</th>
                <th>Rol</th>
                <th>Estatus</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              ${employees.map(u => `
                <tr>
                  <td>${safe(u.name)}</td>
                  <td>${safe(u.email)}</td>
                  <td>${safe(u.phone || '—')}</td>
                  <td><span class="badge ${roleBadge(u.role)}">${safe(u.role)}</span></td>
                  <td>${activeBadge(u.active)}</td>
                  <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-brand me-1" data-edit="${u.id}">Editar</button>
                    <button class="btn btn-sm btn-outline-danger" data-del="${u.id}">Eliminar</button>
                  </td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      </div>

      <div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="userModalTitle">Alta de usuario</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <div class="modal-body">
              <form id="userForm">
                <input type="hidden" id="uId">

                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label">Nombre</label>
                    <input class="form-control" id="uName" required>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" id="uEmail" required>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Teléfono</label>
                    <input class="form-control" id="uPhone">
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Rol</label>
                    <select class="form-select" id="uRole" required>
                      <option value="employee">Empleado</option>
                      <option value="admin">Administrador</option>
                      <option value="optica">Óptica</option>
                    </select>
                  </div>

                  <div id="opticaFields" class="d-none">
                    <div class="row g-3">
                      <div class="col-md-6">
                        <label class="form-label">Contacto de la óptica</label>
                        <input class="form-control" id="uOpticaContacto">
                      </div>

                      <div class="col-md-12">
                        <label class="form-label d-block">Métodos de pago</label>
                        <div class="d-flex flex-wrap gap-3 mt-1">
                          <div class="form-check">
                            <input class="form-check-input js-optica-payment" type="checkbox" id="pmCash" value="1">
                            <label class="form-check-label" for="pmCash">Efectivo</label>
                          </div>

                          <div class="form-check">
                            <input class="form-check-input js-optica-payment" type="checkbox" id="pmTransfer" value="2">
                            <label class="form-check-label" for="pmTransfer">Transferencia</label>
                          </div>

                          <div class="form-check">
                            <input class="form-check-input js-optica-payment" type="checkbox" id="pmCard" value="3">
                            <label class="form-check-label" for="pmCard">Tarjeta</label>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="col-md-3">
                    <label class="form-label">Estatus</label>
                    <select class="form-select" id="uActive">
                      <option value="1">Activo</option>
                      <option value="0">Inactivo</option>
                    </select>
                  </div>

                  <div class="col-md-4">
                    <label class="form-label">Contraseña</label>
                    <div class="input-group">
                      <input type="password" class="form-control" id="uPassword">
                      <button type="button" class="btn btn-outline-secondary" id="btnTogglePass">${eyeIcon(false)}</button>
                    </div>
                  </div>

                  <div class="col-md-5">
                    <label class="form-label">Confirmar contraseña</label>
                    <div class="input-group">
                      <input type="password" class="form-control" id="uPasswordConfirm">
                      <button type="button" class="btn btn-outline-secondary" id="btnTogglePass2">${eyeIcon(false)}</button>
                    </div>
                  </div>
                </div>
              </form>
            </div>

            <div class="modal-footer">
              <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
              <button class="btn btn-brand" id="btnSaveUser">Guardar</button>
            </div>
          </div>
        </div>
      </div>
    `;

    initDt('#tblOpticasUsers');
    initDt('#tblEmployeesUsers');
    wireUi();
  }

  function wireUi() {
    const modalEl = document.getElementById('userModal');
    modal = new bootstrap.Modal(modalEl);

    const roleEl = document.getElementById('uRole');
    const opticaFields = document.getElementById('opticaFields');
    const modalTitle = document.getElementById('userModalTitle');

    const toggleOpticaFields = () => {
      const isOptica = roleEl.value === 'optica';
      opticaFields.classList.toggle('d-none', !isOptica);

      if (!isOptica) {
        document.getElementById('uOpticaContacto').value = '';
        setCheckedPaymentMethods([]);
      }
    };

    const togglePasswordField = (inputId, btnId) => {
      const input = document.getElementById(inputId);
      const btn = document.getElementById(btnId);

      btn?.addEventListener('click', () => {
        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
        btn.innerHTML = eyeIcon(isPassword);
      });
    };

    const resetPasswordButtons = () => {
      const p1 = document.getElementById('uPassword');
      const p2 = document.getElementById('uPasswordConfirm');
      const b1 = document.getElementById('btnTogglePass');
      const b2 = document.getElementById('btnTogglePass2');

      p1.type = 'password';
      p2.type = 'password';
      b1.innerHTML = eyeIcon(false);
      b2.innerHTML = eyeIcon(false);
    };

    const openCreateModal = () => {
      editingUserId = null;
      document.getElementById('userForm').reset();
      document.getElementById('uId').value = '';
      document.getElementById('uActive').value = '1';
      document.getElementById('uRole').innerHTML = `
        <option value="employee">Empleado</option>
        <option value="admin">Administrador</option>
        <option value="optica">Óptica</option>
      `;
      document.getElementById('uRole').value = 'employee';

      document.getElementById('uOpticaContacto').value = '';
      setCheckedPaymentMethods([]);

      modalTitle.textContent = 'Alta de usuario';

      toggleOpticaFields();
      resetPasswordButtons();
      modal.show();
    };

    const openEditModal = (user) => {
      editingUserId = user.id;
      document.getElementById('uId').value = user.id ?? '';
      document.getElementById('uName').value = user.name ?? '';
      document.getElementById('uEmail').value = user.email ?? '';
      document.getElementById('uPhone').value = user.phone ?? '';
      document.getElementById('uActive').value = user.active ? '1' : '0';
      document.getElementById('uPassword').value = '';
      document.getElementById('uPasswordConfirm').value = '';
      document.getElementById('uOpticaContacto').value = user.optica_contacto ?? '';

      if (user.role === 'optica') {
        document.getElementById('uRole').innerHTML = `<option value="optica">Óptica</option>`;
        document.getElementById('uRole').value = 'optica';
      } else {
        document.getElementById('uRole').innerHTML = `
          <option value="employee">Empleado</option>
          <option value="admin">Administrador</option>
        `;
        document.getElementById('uRole').value = user.role ?? 'employee';
      }

      setCheckedPaymentMethods(user.payment_methods || []);

      modalTitle.textContent = 'Editar usuario';

      toggleOpticaFields();
      resetPasswordButtons();
      modal.show();
    };

    roleEl.addEventListener('change', toggleOpticaFields);
    toggleOpticaFields();

    togglePasswordField('uPassword', 'btnTogglePass');
    togglePasswordField('uPasswordConfirm', 'btnTogglePass2');

    newHandler = () => openCreateModal();
    outlet.querySelector('#btnNewUser')?.addEventListener('click', newHandler);

    outletClickHandler = async (e) => {
      const editBtn = e.target.closest('[data-edit]');
      const delBtn = e.target.closest('[data-del]');

      if (editBtn) {
        const editId = editBtn.dataset.edit;
        const user = users.find(x => String(x.id) === String(editId));
        if (!user) {
          Swal.fire('No encontrado', 'No se encontró el usuario.', 'info');
          return;
        }
        openEditModal(user);
        return;
      }

      if (delBtn) {
        const delId = delBtn.dataset.del;

        const confirm = await Swal.fire({
          title: '¿Eliminar usuario?',
          text: 'Esta acción puede afectar registros relacionados.',
          icon: 'warning',
          showCancelButton: true,
          confirmButtonText: 'Sí, eliminar',
          cancelButtonText: 'Cancelar'
        });

        if (!confirm.isConfirmed) return;

        try {
          await usersService.remove(delId);
          await loadData();
          renderTables();
          Swal.fire('Eliminado', 'Usuario eliminado correctamente.', 'success');
        } catch (err) {
          Swal.fire('Error', extractError(err), 'error');
        }
      }
    };

    outlet.addEventListener('click', outletClickHandler);

    saveHandler = async () => {
      const isEdit = !!editingUserId;

      const payload = {
        name: document.getElementById('uName').value.trim(),
        email: document.getElementById('uEmail').value.trim(),
        phone: document.getElementById('uPhone').value.trim() || null,
        role: document.getElementById('uRole').value,
        optica_contacto: document.getElementById('uOpticaContacto').value.trim() || null,
        active: document.getElementById('uActive').value === '1'
      };

      const password = document.getElementById('uPassword').value;
      const passwordConfirmation = document.getElementById('uPasswordConfirm').value;

      if (!payload.name || !payload.email || !payload.role) {
        Swal.fire('Faltan datos', 'Completa nombre, email y rol.', 'info');
        return;
      }

      if (payload.role === 'optica') {
        if (!payload.optica_contacto) {
          Swal.fire('Falta contacto', 'Debes capturar el contacto de la óptica.', 'info');
          return;
        }

        const paymentMethods = getCheckedPaymentMethods();
        if (!paymentMethods.length) {
          Swal.fire('Faltan métodos de pago', 'Selecciona al menos un método de pago para la óptica.', 'info');
          return;
        }

        payload.payment_methods = paymentMethods;
      }

      if (!isEdit) {
        if (!password || !passwordConfirmation) {
          Swal.fire('Faltan datos', 'La contraseña es obligatoria al crear.', 'info');
          return;
        }

        if (password !== passwordConfirmation) {
          Swal.fire('Contraseña inválida', 'Las contraseñas no coinciden.', 'warning');
          return;
        }

        payload.password = password;
        payload.password_confirmation = passwordConfirmation;
      } else if (password || passwordConfirmation) {
        if (password !== passwordConfirmation) {
          Swal.fire('Contraseña inválida', 'Las contraseñas no coinciden.', 'warning');
          return;
        }

        payload.password = password;
        payload.password_confirmation = passwordConfirmation;
      }

      try {
        if (isEdit) {
          await usersService.update(editingUserId, payload);
        } else {
          await usersService.create(payload);
        }

        modal.hide();
        await loadData();
        renderTables();

        Swal.fire(
          'Guardado',
          isEdit ? 'Usuario actualizado correctamente.' : 'Usuario creado correctamente.',
          'success'
        );
      } catch (err) {
        Swal.fire('Error', extractError(err), 'error');
      }
    };

    document.getElementById('btnSaveUser')?.addEventListener('click', saveHandler);
  }

  await loadData();
  renderTables();
}