declare global {
  namespace Cypress {
    interface Chainable {
      loginAsAdmin(): Chainable<void>;
    }
  }
}

Cypress.Commands.add('loginAsAdmin', () => {
  const user = Cypress.env('wpUser');
  const password = Cypress.env('wpPassword');

  cy.session(
    'admin',
    () => {
      cy.visit('/wp-login.php');
      cy.get('#user_login').clear().type(user);
      cy.get('#user_pass').clear().type(password);
      cy.get('#wp-submit').click();
      cy.url().should('contain', '/wp-admin/');
    },
    {
      validate() {
        cy.visit('/wp-admin/');
        cy.url().should('contain', '/wp-admin/');
      },
    },
  );
});

export {};
