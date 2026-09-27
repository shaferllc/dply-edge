---
title: "Quickstart"
description: "Create an account, start your trial, connect a repository and deploy your first app to the edge."
---

This guide takes you from a new account to a live app with a resource and a custom domain. It takes about ten minutes, most of it waiting for the first build.

You need a repository on GitHub, GitLab or Bitbucket, public or private, and a payment card.

## 1. Create your account

Go to [edge.dply.io/register](https://edge.dply.io/register). Sign-up is open; you don't need an invitation. Register with **Name**, **Email** and **Password**, or choose one of the providers under **Or continue with** to sign up with that account.

If you registered with email, open the verification link dply sends you. dply creates an organization for you when you sign up.

## 2. Start your trial

dply has no free plan. A new organization starts with a 5-day trial of the plan you choose, and Checkout asks for a card before the trial begins.

| Trial | |
|---|---|
| Length | 5 days |
| Plan | Pro |
| Card | Required at Checkout |
| Spending cap | $2 of usage. Past that, builds and container traffic pause until you pay. |
| After the trial | Billed for Pro ($20/mo) on day 6 unless you cancel first |

After you verify your email, open your organization's **Billing** page. dply takes you there after sign-up when it can. Under **Plan**, choose a plan's **Start 5-day trial** button (for example **Start 5-day Pro trial**) and complete Stripe Checkout.

> [!TIP]
> You can also skip this step. The first time you choose **Deploy** without a plan, dply sends you to Checkout and brings you back to your draft afterwards.

If a trial ends without payment, the organization is paused: apps stop serving and builds stop. See [Free trial](/docs/free-trial) and [Paused accounts](/docs/paused-accounts).

## 3. Connect your source control

From the dashboard, choose **New app**. The **Create an app** page walks you through three steps.

In **Step 1 of 3 · Connect your source control**, choose **Connect GitHub, GitLab, or Bitbucket** and authorize dply. When the account shows as **Connected**, choose **Next**.

You can also type a repository under **Or paste a repository**, as `owner/repo` or a full URL, and choose **Next**.

## 4. Choose a repository

In **Step 2 of 3 · Select a repository**, pick the repository from the list and choose **Next**. If the repository you want isn't listed, choose **Reload**, or paste its URL under **Repository URL**.

## 5. Deploy

In **Step 3 of 3 · Create your application**:

1. Enter an **App name**. dply fills one in from the repository name.
2. Pick the branch or tag to deploy.
3. Wait for **Detecting how to build this…** to finish. dply then shows the framework and what it will deploy, for example `astro · Site` or `laravel · App`.
4. If the repository is a monorepo, choose the package under **Which package should we deploy?**
5. Under **What are you deploying?**, keep the choice marked **Recommended** or pick the other one:
   - **Site**: static files built from your repository, served from the edge.
   - **App**: dply runs your code. Server-rendered frameworks such as Next.js render on the edge; PHP, Ruby and Node.js servers run in a container.
6. Choose **Deploy**.

To change the **Build command** or **Output directory** dply detected, open **Advanced** before you deploy. You can also change both later, in **Build**. See [Frameworks & runtimes](/docs/frameworks#override-the-detected-settings).

> [!NOTE]
> **Advanced** also has **Send server routes to my own server (hybrid)**: static files come from the edge and everything else goes to a server you already run. It needs that server's **Origin URL**. See [Static & hybrid sites](/docs/static-and-hybrid).

## 6. Open your app

After you choose **Deploy**, dply opens the app's **Overview** page and shows the build as it runs. To read the full build output, open **Build & deploy logs**.

When the build finishes, the live URL appears at the top of **Overview**. Every app gets a hostname on `on-dply.live`, in the form `<app-name>-<random>.on-dply.live`. Choose **Open** to visit it.

For a repository on a connected GitHub account, each push to the branch you picked now deploys automatically. The **Deploy on push** setting in **Build** controls this, and is on by default. For other repositories, deploy from the dashboard or the [CLI](/docs/cli).

## 7. Add a resource

Resources are managed services your app uses, such as a key-value store, a database or a queue.

> [!NOTE]
> Resources need server code. You can add them to **Worker SSR**, **Hybrid** and **Container** apps. Static apps can't use them.

1. On your app's **Overview** page, choose **Add resource**.
2. Pick a type, for example **Key-value store**.
3. Choose **Create new** to make a new one, or **Attach existing** to reuse one your organization already has.
4. Enter a **Name** and choose **Create**.
5. Redeploy the app. dply connects resources and sets their environment variables on the next deploy.

See [Resources overview](/docs/resources) for every resource type and how your code reaches it.

## 8. Add a custom domain

1. In your app, open **Routing**, then the **Domains** tab.
2. Under **Custom domains**, enter the **Hostname**, for example `www.example.com`, and choose **Attach domain**.
3. At your DNS provider, create the records dply shows: a CNAME pointing at the **CNAME target**, plus the ownership and verification TXT records when they're listed.
4. Choose **Verify DNS**. dply issues a TLS certificate after DNS checks out. The domain shows **TLS active** when it's ready.

See [Domains](/docs/domains) and [Domain verification](/docs/domain-verification) for apex domains and troubleshooting.

## Next steps

- [Deployments](/docs/deployments): redeploy, roll back and read build logs.
- [Environment variables](/docs/environment-variables): add configuration and secrets.
- [Preview deployments](/docs/preview-deployments): get a URL for every pull request.
- [Local development](/docs/local-development): work with your app from your machine and the CLI.
