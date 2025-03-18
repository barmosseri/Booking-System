<div class="date-picker-container" dir="rtl">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@easepick/bundle@1.2.0/dist/index.css">
  <script src="https://cdn.jsdelivr.net/npm/@easepick/bundle@1.2.0/dist/index.umd.min.js"></script>

  <div style="width: 100%; flex-grow: 1;">
    <label for="date-range"></label><br>
    <input
      type="text"
      id="date-range"
      name="date-range"
      required
      style="width: 100%;"
      readonly
      placeholder="בחירת תאריכים">
    <span class="easepick-wrapper" style="position: absolute; pointer-events: none;"></span>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", async function () {
    async function fetchBlockedDates() {
        try {
            const response = await fetch('/wp-json/booking/v1/blocked-dates');
            const data = await response.json();
            return Array.isArray(data.blocked_dates) ? data.blocked_dates : [];
        } catch (error) {
            console.error('Error fetching blocked dates:', error);
            return [];
        }
    }

    const blockedDates = await fetchBlockedDates();
    console.log("🔒 תאריכים חסומים מ-API:", blockedDates);
    function initializeDatePicker(blockedDates) {
        const blockedDatesFormatted = blockedDates.map(date => new easepick.DateTime(date, 'YYYY-MM-DD'));

        const today = new Date();
        today.setHours(0, 0, 0, 0);

        const picker = new easepick.create({
            element: document.getElementById('date-range'),
            css: ['https://cdn.jsdelivr.net/npm/@easepick/bundle@1.2.0/dist/index.css'],
            lang: 'he-IL',
            firstDay: 0,
            i18n: {
                dayNames: ['ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי', 'שישי', 'שבת'],
                dayNamesShort: ['א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ש'],
                monthNames: ['ינואר', 'פברואר', 'מרץ', 'אפריל', 'מאי', 'יוני', 'יולי', 'אוגוסט', 'ספטמבר', 'אוקטובר', 'נובמבר', 'דצמבר'],
                buttons: { apply: 'בחירה', cancel: 'ביטול', clear: 'ניקוי' }
            },
            plugins: ['RangePlugin', 'LockPlugin'],
            LockPlugin: {
                minDate: today,
                minDays: 5,
                maxDays: 31,
                // כאן מתבצעת בדיקת חסימות – לא משתנה
                filter(date) {
                    return blockedDatesFormatted.some(blocked => blocked.format('YYYY-MM-DD') === date.format('YYYY-MM-DD'));
                }
            },
            RangePlugin: {
                tooltipNumber(num) { return num - 1; },
                locale: { one: 'לילה', other: 'לילות' }
            },
            setup(picker) {
                picker.on('select', (e) => {
                    const { start, end } = e.detail;
                    let nights = end.diff(start, 'days');
                    const selectedRange = [];
                    let tempDate = new easepick.DateTime(start);
                    while (tempDate.diff(end, 'days') <= 0) {
                        selectedRange.push(tempDate.format('YYYY-MM-DD'));
                        tempDate = tempDate.add(1, 'day');
                    }

                    const hasBlockedDate = selectedRange.some(date =>
                        blockedDatesFormatted.some(blocked => blocked.format('YYYY-MM-DD') === date)
                    )
                  
                    const totalCost = calculateTotalCost(nights);
                    localStorage.setItem('selectedStartDate', start.toISOString());
                    localStorage.setItem('selectedEndDate', end.toISOString());
                    localStorage.setItem('selectedTotalCost', totalCost);
                    document.getElementById('nights-count').textContent = nights;
                    document.getElementById('total-cost').textContent = totalCost.toFixed(2);
                    document.getElementById('cost-display').style.display = 'block';
                    picker.setDate([start, end]);
                    updateHiddenFields(start, end, totalCost);
                });
            }
        });
    }

    function updateHiddenFields(start, end, totalCost) {
        const startVal = start.toISOString().split('T')[0];
        const endVal = end.toISOString().split('T')[0];

        const dateField = document.getElementById('form-field-my_hidden_date_field');
        if (dateField) {
            dateField.value = `${startVal} עד ${endVal}`;
            console.log('[DEBUG] Date field updated:', dateField.value);
        } else {
            console.error('[DEBUG] Date field #form-field-my_hidden_date_field not found!');
        }

        const costField = document.getElementById('form-field-my_hidden_cost_field');
        if (costField) {
            costField.value = totalCost.toFixed(2);
            console.log('[DEBUG] Cost field updated:', costField.value);
        } else {
            console.error('[DEBUG] Cost field #form-field-my_hidden_cost_field not found!');
        }
    }

    const savedStart = localStorage.getItem('selectedStartDate');
    const savedEnd = localStorage.getItem('selectedEndDate');
    const savedCost = localStorage.getItem('selectedTotalCost');

    if (savedStart && savedEnd && savedCost) {
        const startDate = new Date(savedStart);
        const endDate = new Date(savedEnd);
        updateHiddenFields(startDate, endDate, parseFloat(savedCost));
    }
    initializeDatePicker(blockedDates);
    function updateBlockedDates() {
        const dateField = document.getElementById('form-field-my_hidden_date_field');
        if (!dateField || !dateField.value) {
            console.error('[DEBUG] No date range selected.');
            return;
        }
        const rangeParts = dateField.value.split(' עד ');
        if (rangeParts.length !== 2) {
            console.error('[DEBUG] Date range format is incorrect:', dateField.value);
            return;
        }
        const startDate = new Date(rangeParts[0]);
        const endDate = new Date(rangeParts[1]);
        let dates = [];
        for (let d = new Date(startDate); d <= endDate; d.setDate(d.getDate() + 1)) {
            dates.push(new Date(d).toISOString().split('T')[0]);
        }
        console.log('[DEBUG] Dates to send for blocking:', dates);
        fetch('/wp-json/booking/v1/update-blocked-dates', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ blocked_dates: dates })
        })
        .then(response => {
            console.log('[DEBUG] Update blocked dates response status:', response.status);
            return response.json();
        })
        .then(data => {
            console.log('[DEBUG] Blocked dates updated:', data);
        })
        .catch(error => {
            console.error('[DEBUG] Error updating blocked dates:', error);
        });
    }

    const formElement = document.querySelector('form');
    if (formElement) {
        formElement.addEventListener('submit', function (event) {
            console.log('[DEBUG] Form submitted. Updating blocked dates...');
            updateBlockedDates();
        });
    } else {
        console.error('[DEBUG] Form element not found!');
    }
});
</script>

<div id="cost-display" style="display:none;">
  <span id="nights-count"></span> לילות - סה״כ <span id="total-cost"></span> ₪
</div>
