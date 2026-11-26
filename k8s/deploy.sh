#!/usr/bin/env bash
# Kubernetes Deployment Script for Liberu Boilerplate Laravel

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

NAMESPACE="${NAMESPACE:-accounting-erp-laravel}"
ENVIRONMENT="${ENVIRONMENT:-production}"
DOMAIN="${DOMAIN:-accounting.example.com}"
IMAGE_TAG="${IMAGE_TAG:-}"
IMAGE_REPOSITORY="${IMAGE_REPOSITORY:-ghcr.io/liberusoftware/accounting-erp-laravel}"
APP_KEY="${APP_KEY:-}"
DB_PASSWORD="${DB_PASSWORD:-}"
DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-}"

info()  { echo -e "${GREEN}[INFO]${NC} $1"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; }

echo -e "${GREEN}=== Liberu Boilerplate Kubernetes Deployment ===${NC}"

command -v kubectl >/dev/null 2>&1 || { error "kubectl not installed"; exit 1; }

[ -z "$APP_KEY" ]          && { error "APP_KEY is required (php artisan key:generate --show)"; exit 1; }
[ -z "$DB_PASSWORD" ]      && { error "DB_PASSWORD is required"; exit 1; }
[ -z "$DB_ROOT_PASSWORD" ] && { error "DB_ROOT_PASSWORD is required"; exit 1; }
[ "$ENVIRONMENT" = "production" ] && [ -z "$IMAGE_TAG" ] && { error "IMAGE_TAG is required for production deployments"; exit 1; }
if [ "$ENVIRONMENT" = "production" ] && [[ ! "$IMAGE_TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    error "IMAGE_TAG must be a semantic version tag such as v2.0.0"
    exit 1
fi
if [[ ! "$IMAGE_REPOSITORY" =~ ^ghcr\.io/[a-z0-9][a-z0-9._/-]+$ ]]; then
    error "IMAGE_REPOSITORY must be a lowercase GitHub Container Registry path"
    exit 1
fi

info "Creating namespace: $NAMESPACE"
kubectl create namespace "$NAMESPACE" --dry-run=client -o yaml | kubectl apply -f -

info "Updating secrets..."
kubectl create secret generic accounting-erp-secrets \
    --from-literal=APP_KEY="$APP_KEY" \
    --from-literal=DB_USERNAME="liberu" \
    --from-literal=DB_PASSWORD="$DB_PASSWORD" \
    --from-literal=DB_ROOT_PASSWORD="$DB_ROOT_PASSWORD" \
    --from-literal=REDIS_PASSWORD="" \
    --namespace="$NAMESPACE" \
    --dry-run=client -o yaml | kubectl apply -f -

info "Deploying to $ENVIRONMENT..."
if [ "$ENVIRONMENT" = "production" ]; then
    kubectl kustomize "k8s/overlays/$ENVIRONMENT" \
        | sed "s#ghcr.io/liberusoftware/accounting-erp-laravel:v2.0.0#${IMAGE_REPOSITORY}:${IMAGE_TAG}#g" \
        | kubectl apply -f -
else
    kubectl apply -k "k8s/overlays/$ENVIRONMENT"
fi

info "Waiting for deployment..."
kubectl wait --for=condition=available --timeout=300s \
    deployment/accounting-erp-laravel -n "$NAMESPACE"

info "Deployment complete!"
echo ""
echo "  Status:  kubectl get pods -n $NAMESPACE"
echo "  Logs:    kubectl logs -n $NAMESPACE -l app=accounting-erp-laravel"
echo "  URL:     https://$DOMAIN"
