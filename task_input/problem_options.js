(() => {
    const config = window.taskProblemOptionsConfig;
    const problemInput = document.querySelector('input[name="problem"], textarea[name="problem"]');
    if (!config || !problemInput) return;

    const problemField = problemInput.closest('.col-md-6, .col-12');
    const departmentControl = document.getElementById('department');
    if (!problemField || !departmentControl) return;

    const choiceWrapper = document.createElement('div');
    choiceWrapper.className = 'position-relative task-problem-choice';
    problemInput.before(choiceWrapper);
    choiceWrapper.append(problemInput);

    const choiceMenu = document.createElement('div');
    choiceMenu.className = 'list-group position-absolute top-100 start-0 w-100 mt-1 shadow-sm d-none task-problem-choice-menu';
    choiceMenu.style.zIndex = '1050';
    choiceMenu.setAttribute('role', 'listbox');
    choiceWrapper.append(choiceMenu);

    const helpText = document.createElement('div');
    helpText.className = 'form-text';
    helpText.textContent = 'พิมพ์หรือเลือกปัญหาจากรายการ ระบบจะจำเป็นตัวเลือกของทีมนี้';
    choiceWrapper.after(helpText);

    let options = [];
    let saveInProgress = false;
    let closeMenuTimer = null;
    const defaultHelp = 'พิมพ์หรือเลือกปัญหาจากรายการ ระบบจะจำเป็นตัวเลือกของทีมนี้';
    const department = () => departmentControl.value || config.defaultDepartment;
    const matchingOption = () => options.find((option) => option.problem_text === problemInput.value.trim());

    const showMessage = (message, error = false) => {
        helpText.textContent = message;
        helpText.classList.toggle('text-danger', error);
        helpText.classList.toggle('text-success', !error && message !== defaultHelp);
    };

    const request = async (method, data = {}) => {
        const url = `${config.endpoint}?department=${encodeURIComponent(department())}`;
        const fetchOptions = { method, headers: { Accept: 'application/json' } };
        if (method !== 'GET') {
            const body = new URLSearchParams({ ...data, department: department(), csrf_token: config.csrfToken });
            fetchOptions.headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
            fetchOptions.body = body.toString();
        }
        const response = await fetch(url, fetchOptions);
        const result = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(result.message || 'ไม่สามารถจัดการรายการปัญหาได้');
        return result;
    };

    const closeMenu = () => choiceMenu.classList.add('d-none');
    const openMenu = () => {
        renderOptions();
        choiceMenu.classList.remove('d-none');
    };

    const selectOption = (option) => {
        problemInput.value = option.problem_text;
        closeMenu();
        problemInput.focus();
    };

    const deleteOption = async (option) => {
        if (!window.confirm('ต้องการลบปัญหานี้ออกจากรายการของทีมใช่หรือไม่?')) return;
        try {
            await request('POST', { action: 'delete', id: option.id });
            if (problemInput.value.trim() === option.problem_text) problemInput.value = '';
            await loadOptions();
            openMenu();
            showMessage('ลบรายการของทีมแล้ว');
        } catch (error) {
            showMessage(error.message, true);
        }
    };

    const renderOptions = () => {
        const keyword = problemInput.value.trim().toLocaleLowerCase();
        const visibleOptions = options.filter((option) => option.problem_text.toLocaleLowerCase().includes(keyword));
        choiceMenu.replaceChildren();

        if (!visibleOptions.length) {
            const empty = document.createElement('div');
            empty.className = 'list-group-item small text-muted';
            empty.textContent = keyword ? 'ไม่พบรายการที่บันทึกไว้' : 'ยังไม่มีรายการปัญหาที่บันทึกไว้';
            choiceMenu.append(empty);
            return;
        }

        visibleOptions.forEach((option) => {
            const item = document.createElement('div');
            item.className = 'list-group-item d-flex align-items-center gap-2 py-2';

            const selectButton = document.createElement('button');
            selectButton.type = 'button';
            selectButton.className = 'btn btn-link flex-grow-1 p-0 text-start text-decoration-none text-dark';
            selectButton.textContent = option.problem_text;
            selectButton.addEventListener('click', () => selectOption(option));

            const deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'btn btn-sm btn-outline-danger border-0';
            deleteButton.setAttribute('aria-label', 'ลบรายการนี้');
            deleteButton.innerHTML = '<i class="bi bi-x-lg"></i>';
            deleteButton.addEventListener('click', () => deleteOption(option));

            item.append(selectButton, deleteButton);
            choiceMenu.append(item);
        });
    };

    const loadOptions = async () => {
        try {
            const result = await request('GET');
            options = result.options || [];
            renderOptions();
            showMessage(defaultHelp);
        } catch (error) {
            showMessage(error.message, true);
        }
    };

    const rememberProblem = async () => {
        const value = problemInput.value.trim();
        if (!value || value === '-' || saveInProgress || matchingOption()) return;
        saveInProgress = true;
        try {
            await request('POST', { action: 'add', problem_text: value });
            await loadOptions();
            showMessage('บันทึกเป็นตัวเลือกของทีมแล้ว');
        } catch (error) {
            showMessage(error.message, true);
        } finally {
            saveInProgress = false;
        }
    };

    problemInput.addEventListener('focus', () => {
        clearTimeout(closeMenuTimer);
        openMenu();
    });
    problemInput.addEventListener('input', openMenu);
    problemInput.addEventListener('blur', () => {
        closeMenuTimer = setTimeout(() => {
            closeMenu();
            rememberProblem();
        }, 160);
    });
    choiceMenu.addEventListener('mousedown', (event) => event.preventDefault());
    departmentControl.addEventListener('change', () => {
        problemInput.value = '';
        loadOptions();
    });
    document.addEventListener('click', (event) => {
        if (!choiceWrapper.contains(event.target)) closeMenu();
    });
    loadOptions();
})();
