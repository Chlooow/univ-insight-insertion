// Build bar chart
const bars = [
  { day:'S', height:50, type:'stripes' },
  { day:'M', height:75, type:'stripes', highlight:true },
  { day:'T', height:90, type:'solid', pct:'76%' },
  { day:'W', height:60, type:'stripes' },
  { day:'T', height:45, type:'stripes' },
  { day:'F', height:65, type:'stripes' },
  { day:'S', height:30, type:'stripes' },
];

const chart = document.getElementById('barChart');
if (chart) {
  bars.forEach(b => {
    const col = document.createElement('div');
    col.className = 'bar-col';
    const wrap = document.createElement('div');
    wrap.className = 'bar-wrap';
    wrap.style.height = '100%';
    const bg = document.createElement('div');
    bg.className = 'bar-bg';
    bg.style.height = '100%';
    const fill = document.createElement('div');
    fill.className = `bar-fill ${b.type}`;
    fill.style.height = b.height + '%';
    fill.style.position = 'relative';
    if (b.pct) {
      const pct = document.createElement('span');
      pct.className = 'bar-pct';
      pct.textContent = b.pct;
      fill.appendChild(pct);
    }
    bg.appendChild(fill);
    wrap.appendChild(bg);
    col.appendChild(wrap);
    chart.appendChild(col);
  });
}

// Timer
let totalSeconds = 5048; // 01:24:08
let running = true;
const timerDisplay = document.getElementById('timerDisplay');
if (timerDisplay) {
    setInterval(() => {
        if (!running) return;
        totalSeconds++;
        const h = Math.floor(totalSeconds / 3600);
        const m = Math.floor((totalSeconds % 3600) / 60);
        const s = totalSeconds % 60;
        timerDisplay.textContent = [h,m,s].map(n => String(n).padStart(2,'0')).join(':');
    }, 1000);
}

function toggleTimer() {
  running = !running;
  const btn = document.getElementById('pauseBtn');
  if (!btn) return;
  btn.innerHTML = running 
    ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>'
    : '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>';
}

// ── Search Functionality ──

const searchInput = document.querySelector('.search-input-field');
const searchResults = document.getElementById('searchResultsDropdown');
const searchLoader = document.getElementById('searchLoader');

if (searchInput && searchResults) {
    let searchTimeout;
    let selectedIndex = -1;

    searchInput.addEventListener('input', (e) => {
      clearTimeout(searchTimeout);
      const q = e.target.value.trim();
      
      if (q.length < 2) {
        searchResults.classList.remove('active');
        searchResults.innerHTML = '';
        if(searchLoader) searchLoader.classList.remove('active');
        return;
      }

      if(searchLoader) searchLoader.classList.add('active');

      searchTimeout = setTimeout(() => {
        fetch(`search_api.php?q=${encodeURIComponent(q)}`)
          .then(res => res.json())
          .then(data => {
            if (data.success) {
                renderResults(data);
                searchResults.classList.add('active');
                selectedIndex = -1;
            } else {
                console.error('API Error:', data.error);
                searchResults.innerHTML = `<div class="no-results" style="color:var(--red-france)">Erreur : ${data.error}</div>`;
                searchResults.classList.add('active');
            }
          })
          .catch(err => {
            console.error('Fetch error:', err);
            searchResults.innerHTML = '<div class="no-results">Problème de connexion au serveur</div>';
            searchResults.classList.add('active');
          })
          .finally(() => {
            if(searchLoader) searchLoader.classList.remove('active');
          });
      }, 250);
    });

    searchInput.addEventListener('keydown', (e) => {
      const items = searchResults.querySelectorAll('.search-result-item');
      
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
        updateSelection(items);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        selectedIndex = Math.max(selectedIndex - 1, -1);
        updateSelection(items);
      } else if (e.key === 'Enter') {
        if (selectedIndex >= 0 && items[selectedIndex]) {
          e.preventDefault();
          window.location.href = items[selectedIndex].href;
        } else if (items.length > 0) {
          // Si rien n'est sélectionné mais qu'on appuie sur Entrée, on prend le premier
          e.preventDefault();
          window.location.href = items[0].href;
        }
      } else if (e.key === 'Escape') {
        searchResults.classList.remove('active');
        searchInput.blur();
      }
    });

    function updateSelection(items) {
      items.forEach((item, i) => {
        item.classList.toggle('selected', i === selectedIndex);
        if (i === selectedIndex) item.scrollIntoView({ block: 'nearest' });
      });
    }

    document.addEventListener('click', (e) => {
      if (!e.target.closest('.search-container')) {
        searchResults.classList.remove('active');
      }
    });

    window.addEventListener('keydown', (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key === 'f') {
        e.preventDefault();
        searchInput.focus();
      }
    });
}

// ── Modale Information ESR ──
const infoModalHTML = `
<div class="info-modal-overlay" id="infoModalOverlay">
  <div class="info-modal-card">
    <div class="info-icon-wrapper">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
      </svg>
    </div>
    <h2 class="info-modal-title">Source des Données</h2>
    <p class="info-modal-text">
      Les indicateurs présentés sur cette plateforme proviennent des jeux de données officiels de <strong>data.gouv.fr</strong>, fournis par le Ministère de l'Enseignement supérieur et de la Recherche (MESR).
    </p>
    <p class="info-modal-text">
      À ce jour, la dernière mise à jour consolidée pour l'ensemble des établissements d'enseignement supérieur français correspond à l'année d'enquête <strong>2020</strong>.
    </p>
    <button class="info-modal-close" id="closeInfoModal">Compris</button>
    <div class="info-modal-footer">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Données Ouvertes (Open Data)
    </div>
  </div>
</div>
`;

if (!document.getElementById('infoModalOverlay')) {
    document.body.insertAdjacentHTML('beforeend', infoModalHTML);
}

const infoOverlay = document.getElementById('infoModalOverlay');
const closeInfoBtn = document.getElementById('closeInfoModal');

function showInfoModal() {
    infoOverlay.classList.add('active');
}

function hideInfoModal() {
    infoOverlay.classList.remove('active');
}

document.addEventListener('click', (e) => {
    if (e.target.closest('.promo-btn')) {
        showInfoModal();
    } else if (e.target === infoOverlay || e.target === closeInfoBtn) {
        hideInfoModal();
    }
});

// ── Scroll Prompt (Style Jeu Vidéo) ──
const currentPage = window.location.pathname.split('/').pop() || 'index.php';
const hasVisited = localStorage.getItem('visited_' + currentPage);

if (!hasVisited) {
    const scrollPromptHTML = `
    <div class="scroll-prompt" id="scrollPrompt">
      <div class="scroll-pill">
        <div class="scroll-dot"></div>
        <span>Nouveaux indicateurs disponibles en bas</span>
        <svg class="scroll-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
          <polyline points="7 13 12 18 17 13"/><polyline points="7 6 12 11 17 6"/>
        </svg>
      </div>
    </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', scrollPromptHTML);
    const scrollPrompt = document.getElementById('scrollPrompt');
    const scrollContainer = document.querySelector('.content'); // C'est ici que ça scrolle dans votre design

    // On affiche après un petit délai
    setTimeout(() => {
        if (scrollPrompt) scrollPrompt.classList.add('active');
    }, 1500);

    // On cache dès que l'utilisateur scrolle un peu
    if (scrollContainer) {
        scrollContainer.addEventListener('scroll', function handleScroll() {
            if (scrollContainer.scrollTop > 50) {
                scrollPrompt.classList.remove('active');
                localStorage.setItem('visited_' + currentPage, 'true');
                // Nettoyage après l'animation
                setTimeout(() => scrollPrompt.remove(), 600);
                scrollContainer.removeEventListener('scroll', handleScroll);
            }
        });
    }
}

function renderResults(data) {
  if (!searchResults) return;
  let html = '';
  
  const etabs = data.etablissements || [];
  const forms = data.formations || [];

  if (etabs.length === 0 && forms.length === 0) {
    html = '<div class="no-results">Aucun résultat trouvé pour cette recherche.</div>';
  } else {
    if (etabs.length > 0) {
      html += '<div class="search-section-title">Établissements</div>';
      etabs.forEach(etab => {
        html += `
          <a href="etablissement.php?etab_id=${etab.id_etab}" class="search-result-item">
            <div class="search-result-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
            </div>
            <div class="search-result-info">
              <div class="search-result-name">${etab.nom}</div>
              <div class="search-result-sub">${etab.ville || 'Ville non renseignée'}</div>
            </div>
          </a>
        `;
      });
    }

    if (forms.length > 0) {
      html += '<div class="search-section-title">Formations</div>';
      forms.forEach(form => {
        html += `
          <a href="formation.php?form_id=${form.id_diplome}" class="search-result-item">
            <div class="search-result-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
            </div>
            <div class="search-result-info">
              <div class="search-result-name">${form.intitule}</div>
              <div class="search-result-sub">${form.discipline} — ${form.etab_nom}</div>
            </div>
          </a>
        `;
      });
    }
  }
  
  searchResults.innerHTML = html;
}
