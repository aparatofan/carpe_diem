document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('sdc-calendar');
    if(!calendarEl) return;

    // --- FILTER LOGIC ---
    const masterEvents = JSON.parse(sdcVars.events_json);
    let activeFilter = 'all'; 

    const iconMap = {
        'weight': '⚖️', 'shower': '🚿', 'sport': '🚴', 'ykw': '🤫',
        'highlights': '⭐', 'daily_text': '📝', 'talking_head': '🗣️',
        'podcasts': '🎧', 'books': '📖', 'films': '🎬', 'lessons': '🌳', 'posts_entries': '✍️'
    };

    function getFilteredEvents() {
        if(activeFilter === 'all') return masterEvents;
        const filtered = [];
        masterEvents.forEach(evt => {
            if(evt.extendedProps.type === 'holiday' || evt.extendedProps.type === 'event') { filtered.push(evt); return; }
            if(evt.extendedProps[activeFilter]) {
                const newEvt = { ...evt }; 
                newEvt.title = iconMap[activeFilter]; 
                filtered.push(newEvt);
            }
        });
        return filtered;
    }

    // --- RENDER FILTER BAR ---
    const filterContainer = document.getElementById('sdc-filter-bar-container');
    if(filterContainer) {
        const filters = [
            { id: 'all', label: 'All', icon: '' },
            { id: 'shower', label: 'Shower', icon: '🚿' },
            { id: 'sport', label: 'Sport', icon: '🚴' },
            { id: 'ykw', label: 'YKW', icon: '🤫' },
            { id: 'weight', label: 'Weight', icon: '⚖️' },
            { id: 'daily_text', label: 'Text', icon: '📝' },
            { id: 'highlights', label: 'Highlights', icon: '⭐' },
            { id: 'talking_head', label: 'Talking', icon: '🗣️' },
            { id: 'podcasts', label: 'Podcasts', icon: '🎧' },
            { id: 'books', label: 'Books', icon: '📖' },
            { id: 'films', label: 'Films', icon: '🎬' },
            { id: 'lessons', label: 'Lessons', icon: '🌳' },
            { id: 'posts_entries', label: 'Posts', icon: '✍️' }
        ];

        filters.forEach(f => {
            const btn = document.createElement('div');
            btn.className = 'sdc-filter-btn' + (f.id === 'all' ? ' active' : '');
            btn.innerHTML = (f.icon ? f.icon + ' ' : '') + f.label;
            btn.dataset.filter = f.id;
            
            btn.addEventListener('click', function() {
                document.querySelectorAll('.sdc-filter-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                activeFilter = this.dataset.filter;
                refreshCalendarEvents();
            });
            filterContainer.appendChild(btn);
        });
    }

    function refreshCalendarEvents() {
        calendar.removeAllEvents();
        calendar.addEventSource(getFilteredEvents());
    }

    // --- NEW: CSV EXPORT LOGIC ---
    const exportBtn = document.getElementById('sdc-export-btn');
    if(exportBtn) {
        exportBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const monthVal = document.getElementById('sdc-export-month').value;
            if(!monthVal) { alert('Please select a month first.'); return; }

            exportBtn.textContent = 'Generating...';
            exportBtn.disabled = true;

            jQuery.ajax({
                url: sdcVars.ajax_url, 
                type: 'POST',
                data: { action: 'sdc_download_report', security: sdcVars.nonce, month: monthVal },
                success: function(res) {
                    exportBtn.textContent = 'Download CSV';
                    exportBtn.disabled = false;
                    
                    if(res.success) {
                        // Create a Blob from the CSV string
                        const blob = new Blob([res.data], { type: 'text/csv;charset=utf-8;' });
                        const link = document.createElement("a");
                        const url = URL.createObjectURL(blob);
                        
                        link.setAttribute("href", url);
                        link.setAttribute("download", "second_brain_report_" + monthVal + ".csv");
                        link.style.visibility = 'hidden';
                        
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                    } else {
                        alert(res.data); // Show error (e.g., "No data found")
                    }
                },
                error: function() {
                    exportBtn.textContent = 'Download CSV';
                    exportBtn.disabled = false;
                    alert('Server error generating report.');
                }
            });
        });
    }

    // --- CALENDAR INIT ---
    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        firstDay: 1, 
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth' },
        events: masterEvents, 
        eventOrder: '-duration', 
        dateClick: function(info) { openModalForDate(info.dateStr, 'standard'); },
        eventClick: function(info) {
            const dateStr = info.event.startStr || info.event.start.toISOString().slice(0,10);
            if (info.event.id && info.event.id.startsWith('holiday-')) {
                openModalForDate(dateStr, 'holiday_view_only');
            } else if (info.event.id && info.event.id.startsWith('event-')) {
                openModalForDate(dateStr, 'event_view_only');
            } else {
                openModalForDate(dateStr, 'standard');
            }
        }
    });
    calendar.render();

    // ... [MODAL & CKEDITOR LOGIC REMAINS IDENTICAL BELOW] ...
    
    const modal = document.getElementById('sdc-modal');
    const closeBtn = document.querySelector('.sdc-close-btn');
    const prevDayBtn = document.getElementById('sdc-btn-prev-day');
    const nextDayBtn = document.getElementById('sdc-btn-next-day');

    const titleEl = document.getElementById('sdc-modal-date-title');
    const loading = document.getElementById('sdc-loading');
    const holidayList = document.getElementById('sdc-holiday-list');
    const holidayDisplay = document.getElementById('sdc-holiday-display-area');
    const viewImageWrapper = document.getElementById('sdc-view-image-wrapper'); 

    const tabs = document.querySelectorAll('.sdc-tab-btn');
    const panes = document.querySelectorAll('.sdc-tab-pane');
    const contentTabBtn = document.getElementById('sdc-tab-btn-content');
    const holidaysTabBtn = document.getElementById('sdc-tab-btn-holidays');
    const addHolidayWrapper = document.getElementById('sdc-add-holiday-wrapper');
    const activeHolidaysTitle = document.getElementById('sdc-active-holidays-title');
    const eventList = document.getElementById('sdc-event-list');
    const eventDisplay = document.getElementById('sdc-event-display-area');
    const eventsTabBtn = document.getElementById('sdc-tab-btn-events');
    const addEventWrapper = document.getElementById('sdc-add-event-wrapper');
    const activeEventsTitle = document.getElementById('sdc-active-events-title');

    const editors = {}; 

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('active'));
            panes.forEach(p => p.style.display = 'none');
            tab.classList.add('active');
            const activePane = document.querySelector('#sdc-tab-' + tab.dataset.tab + '-area');
            if(activePane) activePane.style.display = 'block';
        });
    });

    const closeModal = () => { 
        modal.style.display = 'none'; 
        destroyEditors();
    };

// --- DAY NAVIGATION (PREVIOUS / NEXT) ---
function sdcGetDateAdjusted(dateStr, days) {
    // dateStr is YYYY-MM-DD
    const date = new Date(dateStr + 'T00:00:00');
    date.setDate(date.getDate() + days);
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function sdcNavigateDay(deltaDays) {
    const current = document.getElementById('sdc_date_field')?.value;
    if (!current) return;
    const next = sdcGetDateAdjusted(current, deltaDays);
    openModalForDate(next, 'standard');
}

if (prevDayBtn) prevDayBtn.addEventListener('click', () => sdcNavigateDay(-1));
if (nextDayBtn) nextDayBtn.addEventListener('click', () => sdcNavigateDay(1));

    if(closeBtn) closeBtn.onclick = closeModal;
    window.onclick = (e) => { if (e.target == modal) closeModal(); };

    const editTopBtn = document.getElementById('sdc-btn-switch-to-edit-top');

    const showView = () => { 
        document.getElementById('sdc-view-mode').style.display='block'; 
        document.getElementById('sdc-edit-mode').style.display='none'; 
        if(editTopBtn) editTopBtn.style.display = 'inline-block';
    };

    const showEdit = () => { 
        document.getElementById('sdc-view-mode').style.display='none'; 
        document.getElementById('sdc-edit-mode').style.display='block'; 
        if(editTopBtn) editTopBtn.style.display = 'none';
        initEditors();
    };

    if(editTopBtn) editTopBtn.addEventListener('click', showEdit);
    if(document.getElementById('sdc-btn-switch-to-edit')) document.getElementById('sdc-btn-switch-to-edit').addEventListener('click', showEdit);
    if(document.getElementById('sdc-cancel-edit-btn')) document.getElementById('sdc-cancel-edit-btn').addEventListener('click', showView);

    // --- CKEDITOR HELPERS ---
    function initEditors() {
        if (Object.keys(editors).length > 0) return;
        const textareas = document.querySelectorAll('.sdc-editor');
        textareas.forEach(el => {
            if(!editors[el.id]) {
                ClassicEditor
                    .create(el, { toolbar: [ 'bold', 'italic', '|', 'undo', 'redo' ] })
                    .then(editor => { editors[el.id] = editor; })
                    .catch(error => { console.error(error); });
            }
        });
    }

    function destroyEditors() {
        for (const id in editors) {
            if (editors[id]) {
                editors[id].destroy().then(() => { delete editors[id]; });
            }
        }
    }

    function updateEditorContent() {
        for (const id in editors) {
            if (editors[id]) { editors[id].updateSourceElement(); }
        }
    }

    // --- UPLOADERS ---
    var dailyUploader;
    const uploadBtn = document.getElementById('sdc-upload-btn');
    if(uploadBtn) {
        uploadBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (dailyUploader) { dailyUploader.open(); return; }
            dailyUploader = wp.media.frames.file_frame = wp.media({ title: 'Select Image', button: { text: 'Use Image' }, multiple: false });
            dailyUploader.on('select', function() { 
                var attachment = dailyUploader.state().get('selection').first().toJSON();
                document.getElementById('sdc_image_url').value = attachment.url;
                document.getElementById('sdc_image_caption').value = attachment.caption || ''; 
            });
            dailyUploader.open();
        });
    }
    var holidayUploader;
    const holidayUploadBtn = document.getElementById('sdc-holiday-upload-btn');
    if(holidayUploadBtn) {
        holidayUploadBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (holidayUploader) { holidayUploader.open(); return; }
            holidayUploader = wp.media.frames.file_frame = wp.media({ title: 'Select Holiday Image', button: { text: 'Use Image' }, multiple: false });
            holidayUploader.on('select', function() { document.getElementById('sdc_holiday_image').value = holidayUploader.state().get('selection').first().toJSON().url; });
            holidayUploader.open();
        });
    }

    // --- MAIN LOGIC ---
    function openModalForDate(dateStr, mode = 'standard') {
        modal.style.display = 'block';
        
        if ( ! sdcVars.can_edit ) {
            loading.style.display = 'none';
            titleEl.textContent = 'Date: ' + dateStr;
            const viewMode = document.getElementById('sdc-view-mode');
            viewMode.style.display = 'block';
            viewMode.innerHTML = '<div style="padding:40px 20px; text-align:center; color:#555; font-size:1.1em; line-height:1.5;">' +
                                 '<div style="font-size:3em; margin-bottom:15px;">🔒</div>' +
                                 '<strong>Details of this day are only visible to me.</strong><br>' +
                                 'Thanks for visiting!' +
                                 '</div>';
            const editMode = document.getElementById('sdc-edit-mode');
            if(editMode) editMode.style.display = 'none';
            const tabContainer = document.querySelector('.sdc-tabs');
            if(tabContainer) tabContainer.style.display = 'none';
            return; 
        }

        loading.style.display = 'block';
        destroyEditors(); 

        if (mode === 'holiday_view_only') {
            titleEl.textContent = 'Holiday Details: ' + dateStr;
            if(editTopBtn) editTopBtn.style.display = 'none';
            if(contentTabBtn) contentTabBtn.style.display = 'none'; 
            if(holidaysTabBtn) holidaysTabBtn.click(); 
            if(addHolidayWrapper) addHolidayWrapper.style.display = 'none'; 
            if(activeHolidaysTitle) activeHolidaysTitle.style.display = 'none'; 
        } else if (mode === 'event_view_only') {
            titleEl.textContent = 'Event Details: ' + dateStr;
            if(editTopBtn) editTopBtn.style.display = 'none';
            if(contentTabBtn) contentTabBtn.style.display = 'none';
            if(eventsTabBtn) eventsTabBtn.click();
            if(addEventWrapper) addEventWrapper.style.display = 'none';
            if(activeEventsTitle) activeEventsTitle.style.display = 'none';
        } else {
            titleEl.textContent = 'Date: ' + dateStr;
            if(editTopBtn) editTopBtn.style.display = 'inline-block';
            if(contentTabBtn) contentTabBtn.style.display = 'block';
            if(tabs.length > 0) tabs[0].click(); 
            if(addHolidayWrapper) addHolidayWrapper.style.display = 'block';
            if(activeHolidaysTitle) activeHolidaysTitle.style.display = 'block';
            if(addEventWrapper) addEventWrapper.style.display = 'block';
            if(activeEventsTitle) activeEventsTitle.style.display = 'block';
        }

        document.getElementById('sdc_date_field').value = dateStr;
        document.getElementById('sdc_holiday_start').value = dateStr; 
        document.getElementById('sdc_holiday_end').value = dateStr;   
        document.getElementById('sdc_holiday_title').value = '';
        document.getElementById('sdc_holiday_image').value = '';
        document.getElementById('sdc_image_caption').value = ''; 
        document.getElementById('sdc_event_start').value = dateStr;
        document.getElementById('sdc_event_end').value = dateStr;
        document.getElementById('sdc_event_title').value = '';
        document.getElementById('sdc_event_image').value = '';

        jQuery.ajax({
            url: sdcVars.ajax_url, type: 'POST',
            data: { action: 'sdc_get_content', security: sdcVars.nonce, date: dateStr },
            success: function(res) {
                loading.style.display = 'none';
                if(res.success) {
                    
                    // A. Render Holidays
                    holidayDisplay.innerHTML = ''; 
                    if(res.data.holidays && res.data.holidays.length > 0) {
                        res.data.holidays.forEach(h => {
                            const card = document.createElement('div');
                            card.className = 'sdc-holiday-card';
                            let imgHtml = h.image_url ? '<img src=\"'+h.image_url+'\">' : '';
                            card.innerHTML = imgHtml + '<h3>'+h.title+'</h3><div class=\"sdc-holiday-dates\">📅 '+h.start_date+' to '+h.end_date+'</div>';
                            holidayDisplay.appendChild(card);
                        });
                    }

                    // A2. Render Events
                    if(eventDisplay) {
                        eventDisplay.innerHTML = '';
                        if(res.data.events && res.data.events.length > 0) {
                            res.data.events.forEach(ev => {
                                const card = document.createElement('div');
                                card.className = 'sdc-event-card';
                                let imgHtml = ev.image_url ? '<img src=\"'+ev.image_url+'\">' : '';
                                card.innerHTML = imgHtml + '<h3>'+ev.title+'</h3><div class=\"sdc-event-dates\">📅 '+ev.start_date+' to '+ev.end_date+'</div>';
                                eventDisplay.appendChild(card);
                            });
                        }
                    }

                    // B. Fill Daily Content
                    if(res.data.has_content) {
                        const d = res.data.data;
                        const imgCont = document.getElementById('sdc-view-image-container');
                        const capCont = document.getElementById('sdc-view-image-caption');

                        if(d.image_url) {
                            viewImageWrapper.style.display = 'block';
                            imgCont.innerHTML = '<img src=\"' + d.image_url + '\">';
                            capCont.textContent = d.image_caption || ''; 
                        } else {
                            viewImageWrapper.style.display = 'none';
                            imgCont.innerHTML = '';
                            capCont.textContent = '';
                        }
                        
                        const setTxt = (id, val) => { 
                            const el = document.getElementById(id);
                            if(val) { el.innerHTML = val; } else { el.textContent = '-'; }
                        };
                        
                        setTxt('view_highlights', d.highlights);
                        setTxt('view_daily_text', d.daily_text);
                        setTxt('view_talking_head', d.talking_head);
                        setTxt('view_podcasts', d.podcasts);
                        setTxt('view_books', d.books);
                        setTxt('view_films', d.films);
                        setTxt('view_lessons', d.lessons);
                        setTxt('view_posts_entries', d.posts_entries);

                        const rateEl = document.getElementById('view_films_rating');
                        if(d.films_rating !== null && d.films_rating !== "") {
                            rateEl.textContent = '(' + d.films_rating + '/6)';
                        } else {
                            rateEl.textContent = '';
                        }

                        const weightEl = document.getElementById('view_weight_container');
                        if(d.weight && d.weight > 0) {
                            weightEl.textContent = '⚖️ ' + d.weight + ' kg';
                        } else {
                            weightEl.textContent = '';
                        }

                        let habitHtml = '';
                        habitHtml += (d.ykw == 1 ? '✅ YKW ' : '⬜ YKW ');
                        habitHtml += '<span style=\"margin-left:15px;\"></span>';
                        habitHtml += (d.sport == 1 ? '✅ SPORT ' : '⬜ SPORT ');
                        habitHtml += '<span style=\"margin-left:15px;\"></span>';
                        habitHtml += (d.shower == 1 ? '✅ SHOWER' : '⬜ SHOWER');
                        document.getElementById('view_habits').innerHTML = habitHtml;

                        document.getElementById('sdc_image_url').value = d.image_url || '';
                        document.getElementById('sdc_image_caption').value = d.image_caption || ''; 
                        
                        document.getElementById('sdc_films_rating').value = (d.films_rating !== null) ? d.films_rating : ''; 
                        document.getElementById('sdc_weight').value = d.weight || ''; 

                        document.getElementById('sdc_ykw').checked = (d.ykw == 1);
                        document.getElementById('sdc_sport').checked = (d.sport == 1);
                        document.getElementById('sdc_shower').checked = (d.shower == 1);

                        const fields = ['highlights','daily_text','talking_head','podcasts','books','films','lessons','posts_entries'];
                        fields.forEach(f => {
                            document.getElementById('sdc_' + f).value = d[f] || '';
                        });

                        showView();
                    } else {
                        document.getElementById('sdc-content-form').reset();
                        for (const id in editors) { if(editors[id]) editors[id].setData(''); }
                        viewImageWrapper.style.display = 'none';
                        document.getElementById('sdc_date_field').value = dateStr;
                        document.getElementById('view_films_rating').textContent = ''; 
                        document.getElementById('view_weight_container').textContent = '';
                        showEdit();
                    }

                    // C. Manage Holidays List
                    holidayList.innerHTML = '';
                    if(res.data.holidays && res.data.holidays.length > 0) {
                        res.data.holidays.forEach(h => {
                            const li = document.createElement('li');
                            let imgHtml = h.image_url ? '<img src=\"'+h.image_url+'\" style=\"max-height:100px; display:block; margin-bottom:5px;\">' : '';
                            let deleteLink = (mode === 'standard') ? ' <a href=\"#\" class=\"sdc-del-holiday\" data-id=\"'+h.id+'\" style=\"color:red; float:right;\">[Delete]</a>' : '';
                            li.innerHTML = '<div class=\"sdc-holiday-card\" style=\"margin-bottom:10px;\">' + imgHtml + '<strong>' + h.title + '</strong>' + deleteLink + '<br><span style=\"font-size:0.9em; color:#666;\">(' + h.start_date + ' to ' + h.end_date + ')</span></div>';
                            holidayList.appendChild(li);
                        });
                        if(mode === 'standard') {
                            document.querySelectorAll('.sdc-del-holiday').forEach(btn => {
                                btn.addEventListener('click', function(e) {
                                    e.preventDefault();
                                    if(confirm('Delete holiday?')) {
                                        jQuery.ajax({
                                            url: sdcVars.ajax_url, type: 'POST',
                                            data: { action: 'sdc_delete_holiday', security: sdcVars.nonce, id: this.dataset.id },
                                            success: function() { openModalForDate(dateStr, 'standard'); setTimeout(() => location.reload(), 500); } 
                                        });
                                    }
                                });
                            });
                        }
                    } else {
                        holidayList.innerHTML = '<li>No holidays set for this period.</li>';
                    }

                    // C2. Manage Events List
                    if(eventList) {
                        eventList.innerHTML = '';
                        if(res.data.events && res.data.events.length > 0) {
                            res.data.events.forEach(ev => {
                                const li = document.createElement('li');
                                let imgHtml = ev.image_url ? '<img src=\"'+ev.image_url+'\" style=\"max-height:100px; display:block; margin-bottom:5px;\">' : '';
                                let deleteLink = (mode === 'standard') ? ' <a href=\"#\" class=\"sdc-del-event\" data-id=\"'+ev.id+'\" style=\"color:red; float:right;\">[Delete]</a>' : '';
                                li.innerHTML = '<div class=\"sdc-event-card\" style=\"margin-bottom:10px;\">' + imgHtml + '<strong>' + ev.title + '</strong>' + deleteLink + '<br><span style=\"font-size:0.9em; color:#666;\">(' + ev.start_date + ' to ' + ev.end_date + ')</span></div>';
                                eventList.appendChild(li);
                            });
                            if(mode === 'standard') {
                                document.querySelectorAll('.sdc-del-event').forEach(btn => {
                                    btn.addEventListener('click', function(e) {
                                        e.preventDefault();
                                        if(confirm('Delete event?')) {
                                            jQuery.ajax({
                                                url: sdcVars.ajax_url, type: 'POST',
                                                data: { action: 'sdc_delete_event', security: sdcVars.nonce, id: this.dataset.id },
                                                success: function() { openModalForDate(dateStr, 'standard'); setTimeout(() => location.reload(), 500); }
                                            });
                                        }
                                    });
                                });
                            }
                        } else {
                            eventList.innerHTML = '<li>No events set for this period.</li>';
                        }
                    }
                }
            }
        });
    }

    // --- SAVE HANDLERS ---
    const contentForm = document.getElementById('sdc-content-form');
    if(contentForm) {
        contentForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            updateEditorContent();

            const fd = new FormData(contentForm);
            fd.append('action', 'sdc_save_content');
            fd.append('security', sdcVars.nonce);
            jQuery.ajax({
                url: sdcVars.ajax_url, type: 'POST', data: fd, processData: false, contentType: false,
                success: function(res) {
                    if(res.success) { location.reload(); } 
                    else { alert(res.data); }
                }
            });
        });
    }

    const holidayForm = document.getElementById('sdc-holiday-form');
    if(holidayForm) {
        holidayForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const title = document.getElementById('sdc_holiday_title').value;
            const image = document.getElementById('sdc_holiday_image').value;
            const start = document.getElementById('sdc_holiday_start').value;
            const end = document.getElementById('sdc_holiday_end').value;
            
            jQuery.ajax({
                url: sdcVars.ajax_url, type: 'POST',
                data: { action: 'sdc_add_holiday', security: sdcVars.nonce, title: title, image: image, start: start, end: end },
                success: function(res) {
                    if(res.success) { location.reload(); }
                    else { alert(res.data); }
                }
            });
        });
    }


    const eventForm = document.getElementById('sdc-event-form');
    if(eventForm) {
        eventForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const title = document.getElementById('sdc_event_title').value;
            const image = document.getElementById('sdc_event_image').value;
            const start = document.getElementById('sdc_event_start').value;
            const end = document.getElementById('sdc_event_end').value;
            jQuery.ajax({
                url: sdcVars.ajax_url, type: 'POST',
                data: { action: 'sdc_add_event', security: sdcVars.nonce, title: title, image: image, start: start, end: end },
                success: function(res) {
                    if(res.success) { location.reload(); }
                    else { alert(res.data); }
                }
            });
        });
    }

    var eventUploader;
    const eventUploadBtn = document.getElementById('sdc-event-upload-btn');
    if(eventUploadBtn) {
        eventUploadBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (eventUploader) { eventUploader.open(); return; }
            eventUploader = wp.media.frames.file_frame = wp.media({ title: 'Select Event Image', button: { text: 'Use Image' }, multiple: false });
            eventUploader.on('select', function() { document.getElementById('sdc_event_image').value = eventUploader.state().get('selection').first().toJSON().url; });
            eventUploader.open();
        });
    }

});
