document.addEventListener('DOMContentLoaded', () => {
    const body = document.body;
    const toggleButton = document.getElementById('light-mode-toggle');
    const fontSizeLinks = document.querySelectorAll('.font-size-link');
    
    function getCsrfToken() {
        const metaToken = document.querySelector('meta[name="csrf-token"]');
        return metaToken ? metaToken.getAttribute('content') : '';
    }
    
    // 日夜模式切换
    if (toggleButton) {
        toggleButton.addEventListener('click', () => {
            const csrfToken = getCsrfToken();
            fetch('index.php?mode=1&csrf=' + encodeURIComponent(csrfToken))
                .then(() => {
                    location.reload(); 
                });
        });
    }
    
    // 字体大小切换
    fontSizeLinks.forEach(link => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            const fontSize = link.getAttribute('data-size');
            const csrfToken = getCsrfToken();
            fetch('index.php?size=' + fontSize + '&csrf=' + encodeURIComponent(csrfToken))
                .then(() => {
                    location.reload(); 
                });
        });
    });
    
    // ==================== 工具函数 ====================
    
    // 显示/隐藏密码
    function togglePasswordVisibility(inputId, toggleBtn) {
        const input = document.getElementById(inputId);
        if (input.type === 'password') {
            input.type = 'text';
            toggleBtn.textContent = '隐藏';
        } else {
            input.type = 'password';
            toggleBtn.textContent = '显示';
        }
    }
    
    // 创建密码输入字段（带显示/隐藏按钮）
    function createPasswordField(id, placeholder) {
        return `
            <div class="password-field-container">
                <input type="password" id="${id}" placeholder="${placeholder}" autocomplete="off" maxlength="256">
                <button type="button" class="password-toggle-btn" data-target="${id}">显示</button>
            </div>
        `;
    }
    
    // 显示消息
    function showMessage(element, message, type) {
        element.textContent = message;
        element.className = 'modal-message ' + (type === 'error' ? 'error-message' : 'success-message');
    }
    
    // ==================== 登录模态框 ====================
    
    function createLoginModal() {
        const overlay = document.createElement('div');
        overlay.id = 'secret-modal-overlay';
        overlay.className = 'modal-overlay';
        
        const modal = document.createElement('div');
        modal.className = 'modal-content';
        
        modal.innerHTML = `
            <h3>输入密码</h3>
            <div id="modal-message" class="modal-message"></div>
            ${createPasswordField('secret-password-input', '请输入密码')}
            <div class="modal-buttons">
                <button id="modal-submit-btn" class="modal-btn">确定</button>
                <button id="modal-cancel-btn" class="modal-btn">取消</button>
            </div>
        `;
        
        overlay.appendChild(modal);
        document.body.appendChild(overlay);
        
        return overlay;
    }
    
    function showSecretLogin() {
        let overlay = document.getElementById('secret-modal-overlay');
        if (!overlay) {
            overlay = createLoginModal();
            
            const passwordInput = document.getElementById('secret-password-input');
            const submitBtn = document.getElementById('modal-submit-btn');
            const cancelBtn = document.getElementById('modal-cancel-btn');
            const messageDiv = document.getElementById('modal-message');
            
            // 密码显示/隐藏
            const toggleBtns = overlay.querySelectorAll('.password-toggle-btn');
            toggleBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    const targetId = btn.getAttribute('data-target');
                    togglePasswordVisibility(targetId, btn);
                });
            });
            
            // 点击外部关闭
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    closeModal();
                }
            });
            
            cancelBtn.addEventListener('click', closeModal);
            
            submitBtn.addEventListener('click', () => {
                submitLogin(passwordInput.value, messageDiv, submitBtn);
            });
            
            passwordInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    submitLogin(passwordInput.value, messageDiv, submitBtn);
                }
            });
        }
        
        overlay.style.display = 'flex';
        document.getElementById('secret-password-input').focus();
    }
    
    function closeModal() {
        const overlay = document.getElementById('secret-modal-overlay');
        if (overlay) {
            overlay.style.display = 'none';
            document.getElementById('secret-password-input').value = '';
            document.getElementById('modal-message').textContent = '';
        }
    }
    
    function submitLogin(password, messageDiv, submitBtn) {
        if (!password) {
            showMessage(messageDiv, '请输入密码', 'error');
            return;
        }
        
        if (password.length > 256) {
            showMessage(messageDiv, '密码过长（最多256字符）', 'error');
            return;
        }
        
        submitBtn.disabled = true;
        submitBtn.textContent = '验证中...';
        
        const formData = new FormData();
        formData.append('secret_action', 'login');
        formData.append('password', password);
        formData.append('csrf', getCsrfToken());
        
        fetch('verify.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showMessage(messageDiv, '登录成功，正在跳转...', 'success');
                setTimeout(() => {
                    if (data.must_setup_passwords) {
                        closeModal();
                        showSetupPasswordsModal();
                    } else {
                        location.reload();
                    }
                }, 500);
            } else {
                showMessage(messageDiv, data.message || '登录失败', 'error');
                submitBtn.disabled = false;
                submitBtn.textContent = '确定';
            }
        })
        .catch(error => {
            showMessage(messageDiv, '网络错误，请重试', 'error');
            submitBtn.disabled = false;
            submitBtn.textContent = '确定';
        });
    }
    
    // ==================== 首次设置密码模态框 ====================
    
    function createSetupPasswordsModal() {
        const overlay = document.createElement('div');
        overlay.id = 'setup-passwords-modal-overlay';
        overlay.className = 'modal-overlay';
        
        const modal = document.createElement('div');
        modal.className = 'modal-content modal-large';
        
        modal.innerHTML = `
            <h3>首次登录：设置密码</h3>
            <div id="setup-passwords-message" class="modal-message"></div>
            <div class="info-box">
                主密码：站长专用，可登录和修改所有密码<br>
                用户密码：可分享给他人，只能登录不能修改密码
            </div>
            
            <h4>主密码（站长专用）</h4>
            ${createPasswordField('setup-master-1', '主密码（至少8位，包含数字和字母）')}
            ${createPasswordField('setup-master-2', '再次输入主密码')}
            
            <h4>用户密码（可分享）</h4>
            ${createPasswordField('setup-user-1', '用户密码（至少8位，包含数字和字母）')}
            ${createPasswordField('setup-user-2', '再次输入用户密码')}
            
            <div class="modal-buttons">
                <button id="setup-passwords-submit-btn" class="modal-btn">确定</button>
            </div>
        `;
        
        overlay.appendChild(modal);
        document.body.appendChild(overlay);
        
        return overlay;
    }
    
    function showSetupPasswordsModal() {
        let overlay = document.getElementById('setup-passwords-modal-overlay');
        if (!overlay) {
            overlay = createSetupPasswordsModal();
            
            const submitBtn = document.getElementById('setup-passwords-submit-btn');
            const messageDiv = document.getElementById('setup-passwords-message');
            
            // 密码显示/隐藏
            const toggleBtns = overlay.querySelectorAll('.password-toggle-btn');
            toggleBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    const targetId = btn.getAttribute('data-target');
                    togglePasswordVisibility(targetId, btn);
                });
            });
            
            submitBtn.addEventListener('click', () => {
                const master1 = document.getElementById('setup-master-1').value;
                const master2 = document.getElementById('setup-master-2').value;
                const user1 = document.getElementById('setup-user-1').value;
                const user2 = document.getElementById('setup-user-2').value;
                
                submitSetupPasswords(master1, master2, user1, user2, messageDiv, submitBtn);
            });
        }
        
        overlay.style.display = 'flex';
        document.getElementById('setup-master-1').focus();
    }
    
    function submitSetupPasswords(master1, master2, user1, user2, messageDiv, submitBtn) {
        // 验证填写完整
        if (!master1 || !master2 || !user1 || !user2) {
            showMessage(messageDiv, '请填写完整', 'error');
            return;
        }
        
        // 验证长度
        if (master1.length > 256 || user1.length > 256) {
            showMessage(messageDiv, '密码过长（最多256字符）', 'error');
            return;
        }
        
        // 验证主密码两次输入一致
        if (master1 !== master2) {
            showMessage(messageDiv, '主密码两次输入不一致', 'error');
            return;
        }
        
        // 验证用户密码两次输入一致
        if (user1 !== user2) {
            showMessage(messageDiv, '用户密码两次输入不一致', 'error');
            return;
        }
        
        // 验证两个密码不同
        if (master1 === user1) {
            showMessage(messageDiv, '主密码和用户密码不能相同', 'error');
            return;
        }
        
        submitBtn.disabled = true;
        submitBtn.textContent = '设置中...';
        
        const formData = new FormData();
        formData.append('secret_action', 'setup_passwords');
        formData.append('master_key', master1);
        formData.append('user_key', user1);
        formData.append('csrf', getCsrfToken());
        
        fetch('verify.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showMessage(messageDiv, '密码设置成功，正在刷新...', 'success');
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                showMessage(messageDiv, data.message || '设置失败', 'error');
                submitBtn.disabled = false;
                submitBtn.textContent = '确定';
            }
        })
        .catch(error => {
            showMessage(messageDiv, '网络错误，请重试', 'error');
            submitBtn.disabled = false;
            submitBtn.textContent = '确定';
        });
    }
    
    // ==================== 修改密码模态框 ====================
    
    function createChangePasswordsModal() {
        const overlay = document.createElement('div');
        overlay.id = 'change-passwords-modal-overlay';
        overlay.className = 'modal-overlay';
        
        const modal = document.createElement('div');
        modal.className = 'modal-content modal-large';
        
        modal.innerHTML = `
            <h3>修改密码</h3>
            <div id="change-passwords-message" class="modal-message"></div>
            
            <h4>验证当前主密码</h4>
            ${createPasswordField('current-master-input', '当前主密码')}
            
            <h4>选择要修改的密码</h4>
            <div style="margin-bottom: 15px; text-align: left;">
                <label style="display: block; margin-bottom: 10px;">
                    <input type="checkbox" id="change-master-checkbox"> 修改主密码
                </label>
                <label style="display: block;">
                    <input type="checkbox" id="change-user-checkbox"> 修改用户密码
                </label>
            </div>
            
            <div id="new-master-fields" style="display: none;">
                <h4>新主密码</h4>
                ${createPasswordField('new-master-1', '新主密码（至少8位，包含数字和字母）')}
                ${createPasswordField('new-master-2', '再次输入新主密码')}
            </div>
            
            <div id="new-user-fields" style="display: none;">
                <h4>新用户密码</h4>
                ${createPasswordField('new-user-1', '新用户密码（至少8位，包含数字和字母）')}
                ${createPasswordField('new-user-2', '再次输入新用户密码')}
            </div>
            
            <div class="modal-buttons">
                <button id="change-passwords-submit-btn" class="modal-btn">确定</button>
                <button id="change-passwords-cancel-btn" class="modal-btn">取消</button>
            </div>
        `;
        
        overlay.appendChild(modal);
        document.body.appendChild(overlay);
        
        return overlay;
    }
    
    function showChangePasswordsModal() {
        let overlay = document.getElementById('change-passwords-modal-overlay');
        if (!overlay) {
            overlay = createChangePasswordsModal();
            
            const submitBtn = document.getElementById('change-passwords-submit-btn');
            const cancelBtn = document.getElementById('change-passwords-cancel-btn');
            const messageDiv = document.getElementById('change-passwords-message');
            const changeMasterCheckbox = document.getElementById('change-master-checkbox');
            const changeUserCheckbox = document.getElementById('change-user-checkbox');
            const newMasterFields = document.getElementById('new-master-fields');
            const newUserFields = document.getElementById('new-user-fields');
            
            // 复选框联动
            changeMasterCheckbox.addEventListener('change', () => {
                newMasterFields.style.display = changeMasterCheckbox.checked ? 'block' : 'none';
            });
            
            changeUserCheckbox.addEventListener('change', () => {
                newUserFields.style.display = changeUserCheckbox.checked ? 'block' : 'none';
            });
            
            // 密码显示/隐藏
            const toggleBtns = overlay.querySelectorAll('.password-toggle-btn');
            toggleBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    const targetId = btn.getAttribute('data-target');
                    togglePasswordVisibility(targetId, btn);
                });
            });
            
            // 点击外部关闭
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    closeChangePasswordsModal();
                }
            });
            
            cancelBtn.addEventListener('click', closeChangePasswordsModal);
            
            submitBtn.addEventListener('click', () => {
                const currentMaster = document.getElementById('current-master-input').value;
                const changeMaster = changeMasterCheckbox.checked;
                const changeUser = changeUserCheckbox.checked;
                const newMaster1 = changeMaster ? document.getElementById('new-master-1').value : '';
                const newMaster2 = changeMaster ? document.getElementById('new-master-2').value : '';
                const newUser1 = changeUser ? document.getElementById('new-user-1').value : '';
                const newUser2 = changeUser ? document.getElementById('new-user-2').value : '';
                
                submitChangePasswords(currentMaster, changeMaster, changeUser, 
                                    newMaster1, newMaster2, newUser1, newUser2, 
                                    messageDiv, submitBtn);
            });
        }
        
        overlay.style.display = 'flex';
        document.getElementById('current-master-input').focus();
    }
    
    function closeChangePasswordsModal() {
        const overlay = document.getElementById('change-passwords-modal-overlay');
        if (overlay) {
            overlay.remove();
        }
    }
    
    function submitChangePasswords(currentMaster, changeMaster, changeUser, 
                                   newMaster1, newMaster2, newUser1, newUser2, 
                                   messageDiv, submitBtn) {
        // 验证当前主密码
        if (!currentMaster) {
            showMessage(messageDiv, '请输入当前主密码', 'error');
            return;
        }
        
        if (currentMaster.length > 256) {
            showMessage(messageDiv, '密码过长（最多256字符）', 'error');
            return;
        }
        
        // 验证至少选择一个
        if (!changeMaster && !changeUser) {
            showMessage(messageDiv, '请至少选择修改一个密码', 'error');
            return;
        }
        
        // 验证主密码
        if (changeMaster) {
            if (!newMaster1 || !newMaster2) {
                showMessage(messageDiv, '请完整填写新主密码', 'error');
                return;
            }
            if (newMaster1.length > 256) {
                showMessage(messageDiv, '新主密码过长', 'error');
                return;
            }
            if (newMaster1 !== newMaster2) {
                showMessage(messageDiv, '新主密码两次输入不一致', 'error');
                return;
            }
        }
        
        // 验证用户密码
        if (changeUser) {
            if (!newUser1 || !newUser2) {
                showMessage(messageDiv, '请完整填写新用户密码', 'error');
                return;
            }
            if (newUser1.length > 256) {
                showMessage(messageDiv, '新用户密码过长', 'error');
                return;
            }
            if (newUser1 !== newUser2) {
                showMessage(messageDiv, '新用户密码两次输入不一致', 'error');
                return;
            }
        }
        
        // 验证两个新密码不相同
        if (changeMaster && changeUser && newMaster1 === newUser1) {
            showMessage(messageDiv, '主密码和用户密码不能相同', 'error');
            return;
        }
        
        submitBtn.disabled = true;
        submitBtn.textContent = '修改中...';
        
        const formData = new FormData();
        formData.append('secret_action', 'change_passwords');
        formData.append('current_master', currentMaster);
        formData.append('change_master', changeMaster ? 'true' : 'false');
        formData.append('change_user', changeUser ? 'true' : 'false');
        if (changeMaster) formData.append('new_master', newMaster1);
        if (changeUser) formData.append('new_user', newUser1);
        formData.append('csrf', getCsrfToken());
        
        fetch('verify.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showMessage(messageDiv, '密码修改成功，正在刷新...', 'success');
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                showMessage(messageDiv, data.message || '修改失败', 'error');
                submitBtn.disabled = false;
                submitBtn.textContent = '确定';
            }
        })
        .catch(error => {
            showMessage(messageDiv, '网络错误，请重试', 'error');
            submitBtn.disabled = false;
            submitBtn.textContent = '确定';
        });
    }
    
    // ==================== 触发器 ====================
    
    // 检查是否应该禁用触发器
    function shouldDisableTrigger() {
        // 如果有登出按钮，说明已登录
        if (document.getElementById('logout-btn') !== null) {
            return true;
        }
        // 如果没有触发区域元素，说明不在首页
        if (document.getElementById('secret-trigger') === null) {
            return true;
        }
        return false;
    }
    
    // 键盘快捷键触发 (Ctrl/Cmd + Alt + .)
    document.addEventListener('keydown', function(e) {
        if (shouldDisableTrigger()) return; // 已登录则不触发
        
        if (e.altKey && e.key === '.') {
            if (e.ctrlKey || e.metaKey) {
                e.preventDefault();
                showSecretLogin();
            }
        }
    });
    
    // 隐藏触发区域（右下角点击3次）
    const secretTrigger = document.getElementById('secret-trigger');
    if (secretTrigger) {
        let clickCount = 0;
        let clickTimer = null;
        
        secretTrigger.addEventListener('click', function() {
            if (shouldDisableTrigger()) return; // 已登录则不触发
            
            clickCount++;
            
            if (clickCount === 1) {
                clickTimer = setTimeout(() => {
                    clickCount = 0;
                }, 2000);
            }
            
            if (clickCount === 3) {
                clearTimeout(clickTimer);
                clickCount = 0;
                showSecretLogin();
            }
        });
    }
    
    // 修改密码按钮
    const changePasswordBtn = document.getElementById('change-password-btn');
    if (changePasswordBtn) {
        changePasswordBtn.addEventListener('click', () => {
            showChangePasswordsModal();
        });
    }
    
    // 登出按钮
    const logoutBtn = document.getElementById('logout-btn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', () => {
            const csrfToken = getCsrfToken();
            window.location.href = 'index.php?secret_action=logout&csrf=' + encodeURIComponent(csrfToken);
        });
    }
});