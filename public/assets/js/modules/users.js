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

export async function renderUsers(outlet) {
  let users = [];

  async function loadData() {
    users = await usersService.list();
    users = Array.isArray(users) ? users : [];
  }

  function renderTables() {
    const employees = users.filter(u => u.role === 'employee');
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
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      </div>

      <div class="card p-3 mb-4">
        <h5 class="mb-3">Usuarios empleados</h5>
        <div class="table-responsive">
          <table id="tblEmployeesUsers" class="table table-striped align-middle" style="width:100%">
            <thead>
              <tr>
                <th>Nombre</th>
                <th>Email</th>
                <th>Teléfono</th>
                <th>Rol</th>
                <th>Estatus</th>
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
              <h5 class="modal-title">Alta de usuario</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <div class="modal-body">
              <form id="userForm">
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label">Nombre</label>
                    <input class="form-control" id="uName" required>
                    <div class="form-text" id="nameHint">
                      Para rol óptica, este nombre también será el nombre de la óptica.
                    </div>
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
                      <option value="optica">Óptica</option>
                      <option value="admin">Administrador</option>
                    </select>
                  </div>

                  <div id="opticaFields" class="d-none">
                    <div class="row g-3">
                      <div class="col-md-6">
                        <label class="form-label">Contacto de la óptica</label>
                        <input class="form-control" id="uOpticaContacto">
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
                      <input type="password" class="form-control" id="uPassword" required>
                      <button type="button" class="btn btn-outline-secondary" id="btnTogglePass">
                        Ver
                      </button>
                    </div>
                  </div>

                  <div class="col-md-5">
                    <label class="form-label">Confirmar contraseña</label>
                    <div class="input-group">
                      <input type="password" class="form-control" id="uPasswordConfirm" required>
                      <button type="button" class="btn btn-outline-secondary" id="btnTogglePass2">
                        Ver
                      </button>
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
    const modal = new bootstrap.Modal(modalEl);

    const roleEl = document.getElementById('uRole');
    const opticaFields = document.getElementById('opticaFields');

    const toggleOpticaFields = () => {
      const isOptica = roleEl.value === 'optica';
      opticaFields.classList.toggle('d-none', !isOptica);

      if (!isOptica) {
        document.getElementById('uOpticaContacto').value = '';
      }
    };

    const togglePasswordField = (inputId, btnId) => {
      const input = document.getElementById(inputId);
      const btn = document.getElementById(btnId);

      btn?.addEventListener('click', () => {
        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
        btn.textContent = isPassword ? 'Ocultar' : 'Ver';
      });
    };

    roleEl.addEventListener('change', toggleOpticaFields);
    toggleOpticaFields();

    togglePasswordField('uPassword', 'btnTogglePass');
    togglePasswordField('uPasswordConfirm', 'btnTogglePass2');

    outlet.querySelector('#btnNewUser')?.addEventListener('click', () => {
      document.getElementById('userForm').reset();
      document.getElementById('uActive').value = '1';
      document.getElementById('uRole').value = 'employee';
      toggleOpticaFields();

      const p1 = document.getElementById('uPassword');
      const p2 = document.getElementById('uPasswordConfirm');
      const b1 = document.getElementById('btnTogglePass');
      const b2 = document.getElementById('btnTogglePass2');

      p1.type = 'password';
      p2.type = 'password';
      b1.textContent = 'Ver';
      b2.textContent = 'Ver';

      modal.show();
    });

    document.getElementById('btnSaveUser')?.addEventListener('click', async () => {
      const payload = {
        name: document.getElementById('uName').value.trim(),
        email: document.getElementById('uEmail').value.trim(),
        phone: document.getElementById('uPhone').value.trim() || null,
        role: document.getElementById('uRole').value,
        optica_contacto: document.getElementById('uOpticaContacto').value.trim() || null,
        password: document.getElementById('uPassword').value,
        password_confirmation: document.getElementById('uPasswordConfirm').value,
        active: document.getElementById('uActive').value === '1'
      };

      if (!payload.name || !payload.email || !payload.role || !payload.password || !payload.password_confirmation) {
        Swal.fire('Faltan datos', 'Completa todos los campos obligatorios.', 'info');
        return;
      }

      if (payload.role === 'optica' && !payload.optica_contacto) {
        Swal.fire('Falta contacto', 'Debes capturar el contacto de la óptica.', 'info');
        return;
      }

      if (payload.password !== payload.password_confirmation) {
        Swal.fire('Contraseña inválida', 'Las contraseñas no coinciden.', 'warning');
        return;
      }

      try {
        await usersService.create(payload);
        modal.hide();
        await loadData();
        renderTables();
        Swal.fire('Guardado', 'Usuario creado correctamente.', 'success');
      } catch (err) {
        Swal.fire('Error', extractError(err), 'error');
      }
    });
  }

  await loadData();
  renderTables();
}