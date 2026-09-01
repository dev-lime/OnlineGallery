(() => {
  const state = { csrf: '', user: null, page: 0, currentPost: null };
  const gallery = document.querySelector('#gallery');
  const more = document.querySelector('#more');
  const postDialog = document.querySelector('#post-dialog');
  const authDialog = document.querySelector('#auth-dialog');
  const postContent = document.querySelector('#post-content');
  const authContent = document.querySelector('#auth-content');
  const uploadArea = document.querySelector('#upload-area');
  const toast = document.querySelector('#toast');

  const element = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const date = value => new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(value.replace(' ', 'T')));
  const notice = message => { toast.textContent = message; toast.classList.add('show'); setTimeout(() => toast.classList.remove('show'), 3200); };

  async function request(action, options = {}) {
    const headers = { 'X-CSRF-Token': state.csrf, ...(options.headers || {}) };
    const separator = action.indexOf('&');
    const name = separator === -1 ? action : action.slice(0, separator);
    const query = separator === -1 ? '' : action.slice(separator);
    const response = await fetch(`api.php?action=${encodeURIComponent(name)}${query}`, { ...options, headers });
    const data = await response.json().catch(() => ({ message: 'Сервер вернул некорректный ответ.' }));
    if (!response.ok || !data.ok) throw new Error(data.message || 'Не удалось выполнить запрос.');
    return data;
  }
  const jsonRequest = (action, body) => request(action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });

  function canDeleteComment(comment) {
    return state.user && (state.user.role === 'admin' || state.user.role === 'moderator' || (comment.user_id && comment.user_id === state.user.id));
  }
  function renderAccount() {
    const account = document.querySelector('#account'); account.replaceChildren();
    uploadArea.classList.toggle('hidden', !state.user);
    if (state.user) {
      account.append(element('span', '', state.user.username));
      const logout = element('button', 'secondary', 'Выйти'); logout.onclick = async () => { try { await request('logout', { method: 'POST' }); state.user = null; renderAccount(); notice('Вы вышли из аккаунта.'); } catch (e) { notice(e.message); } };
      account.append(logout);
    } else {
      const login = element('button', 'secondary', 'Войти'); login.onclick = () => showAuth(false);
      const register = element('button', '', 'Регистрация'); register.onclick = () => showAuth(true);
      account.append(login, register);
    }
  }
  function makeCard(post) {
    const card = element('article', 'card');
    const image = element('img'); image.src = post.image_path; image.alt = post.caption; image.loading = 'lazy';
    const info = element('div', 'card-info');
    info.append(element('p', '', post.caption));
    const meta = element('div', 'meta'); meta.append(element('span', '', `@${post.username}`));
    const actions = element('span');
    const like = element('button', `like ${post.liked ? 'active' : ''}`, `${post.liked ? '♥' : '♡'} ${post.likes_count}`);
    like.onclick = async event => { event.stopPropagation(); try { const result = await jsonRequest('like', { post_id: post.id }); post.liked = result.liked; post.likes_count = result.likes_count; like.textContent = `${result.liked ? '♥' : '♡'} ${result.likes_count}`; like.classList.toggle('active', result.liked); } catch (e) { notice(e.message); } };
    actions.append(like, document.createTextNode(` · ${post.comments_count}`)); meta.append(actions); info.append(meta); card.append(image, info);
    card.onclick = () => openPost(post.id); return card;
  }
  async function loadPosts(reset = false) {
    if (reset) { state.page = 0; gallery.replaceChildren(); }
    try {
      state.page++;
      const data = await request(`posts&page=${state.page}`, { method: 'GET' });
      data.posts.forEach(post => gallery.append(makeCard(post)));
      more.classList.toggle('hidden', data.posts.length < 12);
    } catch (e) { notice(e.message); }
  }
  function commentNode(comment) {
    const item = element('article', 'comment');
    const title = element('div'); title.append(element('b', '', comment.author), document.createTextNode(` · ${date(comment.created_at)}`));
    if (canDeleteComment(comment)) { const del = element('button', 'delete', 'Удалить'); del.onclick = () => deleteComment(comment.id); title.append(del); }
    item.append(title, element('p', '', comment.body)); return item;
  }
  async function openPost(id) {
    try {
      const result = await request(`post&id=${id}`); state.currentPost = result;
      postContent.replaceChildren(); const view = element('div', 'post-view');
      const image = element('img', 'post-image'); image.src = result.post.image_path; image.alt = result.post.caption;
      const side = element('section', 'post-side'); side.append(element('h2', '', `@${result.post.username}`), element('p', 'caption', result.post.caption));
      const like = element('button', `like ${result.post.liked ? 'active' : ''}`, `${result.post.liked ? '♥' : '♡'} ${result.post.likes_count}`);
      like.onclick = async () => { try { const data = await jsonRequest('like', { post_id: result.post.id }); result.post.liked = data.liked; result.post.likes_count = data.likes_count; like.textContent = `${data.liked ? '♥' : '♡'} ${data.likes_count}`; like.classList.toggle('active', data.liked); } catch (e) { notice(e.message); } };
      side.append(like);
      if (state.user && state.user.id === result.post.author_id) { const delPost = element('button', 'delete', 'Удалить публикацию'); delPost.onclick = () => deletePost(result.post.id); side.append(delPost); }
      const list = element('div', 'comment-list'); result.comments.forEach(comment => list.append(commentNode(comment))); side.append(list);
      const form = element('form', 'comment'); const input = element('input'); input.maxLength = 300; input.placeholder = state.user ? 'Ваш комментарий' : 'Комментарий от имени Гость'; input.required = true; const send = element('button', '', 'Отправить'); form.append(input, send);
      form.onsubmit = async event => { event.preventDefault(); try { const data = await jsonRequest('comment', { post_id: result.post.id, body: input.value }); result.comments.push(data.comment); list.append(commentNode(data.comment)); input.value = ''; } catch (e) { notice(e.message); } };
      side.append(form); view.append(image, side); postContent.append(view); postDialog.showModal();
    } catch (e) { notice(e.message); }
  }
  async function deleteComment(id) { if (!confirm('Удалить комментарий?')) return; try { await jsonRequest('delete_comment', { comment_id: id }); openPost(state.currentPost.post.id); } catch (e) { notice(e.message); } }
  async function deletePost(id) { if (!confirm('Удалить публикацию?')) return; try { await jsonRequest('delete_post', { post_id: id }); postDialog.close(); loadPosts(true); } catch (e) { notice(e.message); } }
  function showAuth(register) {
    authContent.replaceChildren(); authContent.append(element('h2', '', register ? 'Создать аккаунт' : 'С возвращением'));
    const form = element('form'); const username = element('input'); username.placeholder = 'Имя пользователя'; username.autocomplete = 'username'; username.required = true;
    const password = element('input'); password.type = 'password'; password.placeholder = 'Пароль (от 6 символов)'; password.autocomplete = register ? 'new-password' : 'current-password'; password.required = true;
    form.append(username, password, element('button', '', register ? 'Зарегистрироваться' : 'Войти'));
    form.onsubmit = async event => { event.preventDefault(); try { const data = await jsonRequest(register ? 'register' : 'login', { username: username.value, password: password.value }); state.user = data.user; renderAccount(); authDialog.close(); notice(register ? 'Аккаунт создан.' : 'Вы вошли в аккаунт.'); } catch (e) { notice(e.message); } };
    const swap = element('p', 'switch'); swap.append(document.createTextNode(register ? 'Уже есть аккаунт? ' : 'Нет аккаунта? ')); const button = element('button', 'link-btn', register ? 'Войти' : 'Регистрация'); button.onclick = () => showAuth(!register); swap.append(button); authContent.append(form, swap); authDialog.showModal();
  }
  document.querySelectorAll('.close').forEach(button => button.onclick = () => button.closest('dialog').close());
  document.querySelector('#upload-form').onsubmit = async event => { event.preventDefault(); const form = event.currentTarget; try { const data = new FormData(form); await request('create_post', { method: 'POST', body: data }); form.reset(); notice('Публикация добавлена.'); loadPosts(true); } catch (e) { notice(e.message); } };
  more.onclick = () => loadPosts();
  (async () => { try { const data = await request('session'); state.csrf = data.csrf; state.user = data.user; renderAccount(); await loadPosts(true); } catch (e) { notice(e.message); } })();
})();
