document.addEventListener('DOMContentLoaded', function () {
  const faqRoot = document.querySelector('[data-faq-root]');
  const faqDataNode = document.getElementById('faq-data');
  const purchaseForms = document.querySelectorAll('.purchase-form');

  if (faqRoot && faqDataNode) {
    try {
      const faqItems = JSON.parse(faqDataNode.textContent);
      faqItems.forEach((item, index) => {
        const wrapper = document.createElement('article');
        wrapper.className = 'faq-item';

        const button = document.createElement('button');
        button.type = 'button';
        button.setAttribute('aria-expanded', index === 0 ? 'true' : 'false');
        button.textContent = item.question;

        const answer = document.createElement('div');
        answer.className = 'faq-answer';
        if (index !== 0) {
          answer.hidden = true;
        }
        answer.innerHTML = `<p>${item.answer}</p>`;

        button.addEventListener('click', () => {
          const expanded = button.getAttribute('aria-expanded') === 'true';
          button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
          answer.hidden = expanded;
        });

        wrapper.appendChild(button);
        wrapper.appendChild(answer);
        faqRoot.appendChild(wrapper);
      });
    } catch (error) {
      console.error('Failed to render FAQ widget', error);
    }
  }

  purchaseForms.forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();

      const submitButton = form.querySelector('button[type="submit"]');
      if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'Creating invoice...';
      }

      try {
        const response = await fetch(form.action, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
          },
          body: new FormData(form),
        });

        const payload = await response.json();
        if (!response.ok || !payload.payment_url) {
          throw new Error(payload.error || 'Unable to create NOWPayments invoice.');
        }

        window.location.href = payload.payment_url;
      } catch (error) {
        window.alert(error.message);
        if (submitButton) {
          submitButton.disabled = false;
          submitButton.textContent = 'Buy with Crypto';
        }
      }
    });
  });
});
