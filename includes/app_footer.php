    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/th.js"></script>
    <script>
        // Shared Flatpickr setup for every application page that uses .date-picker or .datetime-picker.
        (() => {
            if (!window.flatpickr || !flatpickr.l10ns.th) return;

            const formatThaiBuddhistDate = (date, format) => {
                const day = String(date.getDate()).padStart(2, '0');
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const year = date.getFullYear() + 543;
                const time = `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')} น.`;
                return format.includes('H:i') ? `${day}/${month}/${year} ${time}` : `${day}/${month}/${year}`;
            };

            const parseThaiBuddhistDate = (value) => {
                const match = value.trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2}):(\d{2})(?:\s*น\.)?)?$/);
                if (!match) return null;

                const [, day, month, buddhistYear, hour = '0', minute = '0'] = match;
                const gregorianYear = Number(buddhistYear) - 543;
                return new Date(gregorianYear, Number(month) - 1, Number(day), Number(hour), Number(minute));
            };

            const sharedOptions = {
                locale: flatpickr.l10ns.th,
                allowInput: true,
                formatDate: formatThaiBuddhistDate,
                parseDate: parseThaiBuddhistDate
            };

            flatpickr('.date-picker', { ...sharedOptions, dateFormat: 'd/m/Y' });
            flatpickr('.datetime-picker', { ...sharedOptions, enableTime: true, time_24hr: true, dateFormat: 'd/m/Y H:i' });
            flatpickr('.time-picker', { locale: flatpickr.l10ns.th, allowInput: true, enableTime: true, noCalendar: true, time_24hr: true, dateFormat: 'H:i' });
        })();
    </script>
</body>
</html>
