<p>
    <% _t('MemberForgotPasswordEmail.HELLO','Hi') %> $FirstName,
</p>
<p>
    <% _t('MemberForgotPasswordEmail.TEXT1', 'You asked for a password reset') %>
    <a href="$PasswordResetLink" class="btn"><% _t('MemberForgotPasswordEmail.TEXT2', 'Click on this link to reset your password') %></a>
</p>
<p>
    <% _t('MemberForgotPasswordEmail.REGARDS','Best regards') %>,
</p>