/** @type {HTMLDivElement} */
const progress = document.querySelector('#t3cowriter-progress');
/** @type {HTMLParagraphElement} */
const progressText = progress.querySelector('#text');
/** @type {HTMLDivElement} */
const progressBar = progress.querySelector('#bar');
/** @type {HTMLDivElement} */
const progressBarInner = progressBar.querySelector('#bar-inner');
const operationID = document.getElementById('t3cowriter-operationIDField').value;

const url = new URL(TYPO3.settings.ajaxUrls['t3_cowriter_progress'], window.location.origin);
url.searchParams.append('operationID', operationID);



const elem = document.getElementById('t3cowriter_sendbutton');
const progress_div = document.getElementById('t3cowriter-progress');

elem.addEventListener('click', function() {
    console.log('Button wurde geklickt');
    progress_div.style.display = 'block';
    setInterval(async () => {
        const res = await fetch(url);
        if (!res.ok) {
            console.error(res);
            return;
        }


        const body = await res.json();
        const {current, total} = body;

        const percent = (current / total) * 100;

        progressBarInner.style.width = `${percent}%`;
        progressText.innerText = `${current} / ${total} (${percent.toFixed(1)}%)`;
    }, 5 * 1000);
});
